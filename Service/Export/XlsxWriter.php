<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Export;

use MagoAssistant\Mago\Api\Data\ExportColumn;

/**
 * Writes rows as an Excel workbook (.xlsx) with one sheet and a bold, frozen header row.
 *
 * An .xlsx file is a zip of a few XML parts; only the sheet changes per export. Text goes in as
 * inline strings, so no shared string table is needed, and is never read as a formula. Cells of a
 * numeric column are stored as numbers, so the admin can sum and sort them; Excel also opens this
 * the same way whatever the locale's list separator is, which a CSV does not.
 */
class XlsxWriter implements TabularWriterInterface
{
    private const MAIN_NAMESPACE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const RELATIONSHIPS_NAMESPACE = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const DOCUMENT_RELATIONSHIP = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const XML_DECLARATION = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";

    /** Excel refuses longer cell text */
    private const MAX_CELL_LENGTH = 32767;

    /** Style index of the bold header font in styles.xml */
    private const HEADER_STYLE = 1;

    /** Characters XML 1.0 does not allow; Excel reports the file as corrupt when one is present */
    private const INVALID_XML_CHARACTERS = '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u';

    /** The letters a column reference is spelled with */
    private const COLUMN_LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public function getContentType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function getFileExtension(): string
    {
        return 'xlsx';
    }

    public function write(array $columns, iterable $rows): string
    {
        $path = $this->temporaryPath();
        try {
            $this->writeArchive($path, $this->parts($this->sheet($columns, $rows)));
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new SpreadsheetWriteException('Could not read the spreadsheet back from ' . $path);
            }

            return $contents;
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mago-export');
        if ($path === false) {
            throw new SpreadsheetWriteException('Could not create a temporary file for the spreadsheet');
        }

        return $path;
    }

    /**
     * @param array<string, string> $parts File contents keyed by their path in the archive
     */
    private function writeArchive(string $path, array $parts): void
    {
        $archive = new \ZipArchive();
        if ($archive->open($path, \ZipArchive::OVERWRITE) !== true) {
            throw new SpreadsheetWriteException('Could not open the spreadsheet archive at ' . $path);
        }
        foreach ($parts as $name => $contents) {
            $archive->addFromString($name, $contents);
        }
        if (!$archive->close()) {
            throw new SpreadsheetWriteException('Could not write the spreadsheet archive at ' . $path);
        }
    }

    /**
     * @return array<string, string>
     */
    private function parts(string $sheet): array
    {
        return [
            '[Content_Types].xml' => self::XML_DECLARATION
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                . '</Types>',
            '_rels/.rels' => self::XML_DECLARATION
                . '<Relationships xmlns="' . self::RELATIONSHIPS_NAMESPACE . '">'
                . '<Relationship Id="rId1" Type="' . self::DOCUMENT_RELATIONSHIP . '/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml' => self::XML_DECLARATION
                . '<workbook xmlns="' . self::MAIN_NAMESPACE . '" xmlns:r="' . self::DOCUMENT_RELATIONSHIP . '">'
                . '<sheets><sheet name="Export" sheetId="1" r:id="rId1"/></sheets>'
                . '</workbook>',
            'xl/_rels/workbook.xml.rels' => self::XML_DECLARATION
                . '<Relationships xmlns="' . self::RELATIONSHIPS_NAMESPACE . '">'
                . '<Relationship Id="rId1" Type="' . self::DOCUMENT_RELATIONSHIP . '/worksheet" Target="worksheets/sheet1.xml"/>'
                . '<Relationship Id="rId2" Type="' . self::DOCUMENT_RELATIONSHIP . '/styles" Target="styles.xml"/>'
                . '</Relationships>',
            'xl/styles.xml' => self::XML_DECLARATION
                . '<styleSheet xmlns="' . self::MAIN_NAMESPACE . '">'
                . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
                . '<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
                . '<fills count="2"><fill><patternFill patternType="none"/></fill>'
                . '<fill><patternFill patternType="gray125"/></fill></fills>'
                . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
                . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
                . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
                . '</styleSheet>',
            'xl/worksheets/sheet1.xml' => $sheet,
        ];
    }

    /**
     * @param list<ExportColumn> $columns
     * @param iterable<array<string, scalar|null>> $rows
     */
    private function sheet(array $columns, iterable $rows): string
    {
        $xml = self::XML_DECLARATION
            . '<worksheet xmlns="' . self::MAIN_NAMESPACE . '">'
            . '<sheetViews><sheetView workbookViewId="0">'
            . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            . '</sheetView></sheetViews><sheetData>'
            . $this->headerRow($columns);
        $rowNumber = 1;
        foreach ($rows as $row) {
            $xml .= $this->row(++$rowNumber, $columns, $row);
        }

        return $xml . '</sheetData></worksheet>';
    }

    /**
     * @param list<ExportColumn> $columns
     */
    private function headerRow(array $columns): string
    {
        $cells = array_map(
            fn (ExportColumn $column, int $index): string => $this->textCell(
                $this->reference($index, 1),
                $column->label,
                self::HEADER_STYLE
            ),
            $columns,
            array_keys($columns)
        );

        return '<row r="1">' . implode('', $cells) . '</row>';
    }

    /**
     * @param list<ExportColumn> $columns
     * @param array<string, scalar|null> $row
     */
    private function row(int $rowNumber, array $columns, array $row): string
    {
        $cells = array_map(
            fn (ExportColumn $column, int $index): string => $this->cell(
                $this->reference($index, $rowNumber),
                $column,
                $row[$column->key] ?? null
            ),
            $columns,
            array_keys($columns)
        );

        return '<row r="' . $rowNumber . '">' . implode('', $cells) . '</row>';
    }

    private function cell(string $reference, ExportColumn $column, mixed $value): string
    {
        if ($value === null || $value === '' || !is_scalar($value)) {
            return '';
        }
        if ($column->isNumeric && $this->isFiniteNumber($value)) {
            return '<c r="' . $reference . '"><v>' . (is_int($value) ? (string)$value : (string)(float)$value) . '</v></c>';
        }

        return $this->textCell($reference, (string)$value);
    }

    private function isFiniteNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)))
            && is_finite((float)$value);
    }

    private function textCell(string $reference, string $text, ?int $style = null): string
    {
        return '<c r="' . $reference . '" t="inlineStr"' . ($style === null ? '' : ' s="' . $style . '"') . '>'
            . '<is><t xml:space="preserve">' . htmlspecialchars($this->cleanText($text), ENT_XML1 | ENT_QUOTES, 'UTF-8')
            . '</t></is></c>';
    }

    private function cleanText(string $text): string
    {
        $valid = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        return mb_substr((string)preg_replace(self::INVALID_XML_CHARACTERS, '', $valid), 0, self::MAX_CELL_LENGTH);
    }

    /**
     * The A1-style reference of a cell, from a zero-based column index
     */
    private function reference(int $columnIndex, int $rowNumber): string
    {
        $letters = '';
        for ($index = $columnIndex; $index >= 0; $index = intdiv($index, 26) - 1) {
            $letters = self::COLUMN_LETTERS[$index % 26] . $letters;
        }

        return $letters . $rowNumber;
    }
}

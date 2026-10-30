<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Export;

use MagoAssistant\Mago\Api\Data\ExportColumn;

/**
 * Writes rows as a CSV file that spreadsheet programs open as UTF-8.
 */
class CsvWriter implements TabularWriterInterface
{
    private const BOM = "\xEF\xBB\xBF";

    /**
     * Characters that make a spreadsheet read a cell as a formula.
     */
    private const FORMULA_START = ['=', '+', '-', '@', "\t", "\r"];

    public function getContentType(): string
    {
        return 'text/csv; charset=UTF-8';
    }

    public function getFileExtension(): string
    {
        return 'csv';
    }

    public function write(array $columns, iterable $rows): string
    {
        return self::BOM . $this->header($columns) . $this->lines($columns, $rows);
    }

    /**
     * The header line, ending in a newline
     *
     * @param list<ExportColumn> $columns
     */
    private function header(array $columns): string
    {
        return $this->line(array_map(static fn (ExportColumn $column): string => $column->label, $columns)) . "\n";
    }

    /**
     * CSV lines for rows, each ending in a newline
     *
     * @param list<ExportColumn> $columns
     * @param iterable<array<string, mixed>> $rows
     */
    private function lines(array $columns, iterable $rows): string
    {
        $lines = '';
        foreach ($rows as $row) {
            $lines .= $this->line(array_map(
                fn (ExportColumn $column): string => $this->cell($row[$column->key] ?? ''),
                $columns
            )) . "\n";
        }

        return $lines;
    }

    /**
     * Joins cells into one CSV line, quoting where needed.
     *
     * @param list<string> $cells
     */
    private function line(array $cells): string
    {
        return implode(',', array_map(
            static fn (string $cell): string => preg_match('/[",\r\n]/', $cell) === 1 || $cell !== trim($cell)
                ? '"' . str_replace('"', '""', $cell) . '"'
                : $cell,
            $cells
        ));
    }

    /**
     * Prefixes text that a spreadsheet would run as a formula.
     */
    private function cell(mixed $value): string
    {
        $value = is_scalar($value) ? (string)$value : '';

        return $value !== '' && !is_numeric($value) && in_array($value[0], self::FORMULA_START, true)
            ? "'" . $value
            : $value;
    }
}

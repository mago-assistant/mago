<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Export;

use MagoAssistant\Mago\Api\Data\ExportColumn;
use MagoAssistant\Mago\Service\Export\XlsxWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #298: the Excel download of a tool call's full result. The file is opened again as the zip
 * it is, and its sheet read as XML, which is what Excel does with it.
 */
final class XlsxWriterTest extends TestCase
{
    private const MAIN_NAMESPACE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    #[Test]
    public function itHoldsEveryPartAWorkbookNeeds(): void
    {
        $parts = $this->parts((new XlsxWriter())->write($this->columns(), []));

        self::assertSame(
            ['[Content_Types].xml', '_rels/.rels', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/workbook.xml', 'xl/worksheets/sheet1.xml'],
            array_keys($parts)
        );
        foreach ($parts as $name => $xml) {
            self::assertNotFalse(simplexml_load_string($xml), $name . ' is not well-formed XML');
        }
    }

    #[Test]
    public function itWritesABoldHeaderRowFromTheColumnLabels(): void
    {
        $cells = $this->cells((new XlsxWriter())->write($this->columns(), []));

        self::assertSame([['A1', 'inlineStr', '1', 'SKU'], ['B1', 'inlineStr', '1', 'Product name'], ['C1', 'inlineStr', '1', 'Price']], $cells);
    }

    #[Test]
    public function itStoresNumericColumnsAsNumbersAndEverythingElseAsText(): void
    {
        $cells = $this->cells((new XlsxWriter())->write($this->columns(), [
            ['sku' => '00123', 'name' => 'Sprite Yoga Companion Kit', 'price' => 61.5],
            ['sku' => 'MH01', 'name' => 'Chaz Kangeroo Hoodie', 'price' => 'n/a'],
        ]));

        self::assertSame(['A2', 'inlineStr', '', '00123'], $cells[3]);
        self::assertSame(['C2', '', '', '61.5'], $cells[5]);
        self::assertSame(['C3', 'inlineStr', '', 'n/a'], $cells[8]);
    }

    #[Test]
    public function itLeavesEmptyCellsOutAndNeverWritesAFormula(): void
    {
        $cells = $this->cells((new XlsxWriter())->write($this->columns(), [
            ['sku' => '=HYPERLINK("http://evil.test")', 'name' => null, 'price' => ''],
        ]));

        self::assertSame([['A2', 'inlineStr', '', '=HYPERLINK("http://evil.test")']], array_slice($cells, 3));
    }

    #[Test]
    public function itStripsCharactersXmlCannotHoldAndCutsCellsAtTheExcelLimit(): void
    {
        $cells = $this->cells((new XlsxWriter())->write($this->columns(), [
            ['sku' => "A\x01B\x0BC", 'name' => str_repeat('é', 40000), 'price' => 1],
        ]));

        self::assertSame('ABC', $cells[3][3]);
        self::assertSame(32767, mb_strlen($cells[4][3]));
    }

    #[Test]
    public function itNamesColumnsPastZWithTwoLetters(): void
    {
        $columns = array_map(static fn (int $index): ExportColumn => new ExportColumn('c' . $index, 'C' . $index), range(0, 27));

        $cells = $this->cells((new XlsxWriter())->write($columns, []));

        self::assertSame(['Z1', 'AA1', 'AB1'], array_column(array_slice($cells, 25), 0));
    }

    #[Test]
    public function itDescribesItselfAsAnExcelWorkbook(): void
    {
        $writer = new XlsxWriter();

        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $writer->getContentType());
        self::assertSame('xlsx', $writer->getFileExtension());
    }

    /**
     * @return list<ExportColumn>
     */
    private function columns(): array
    {
        return [
            new ExportColumn('sku', 'SKU'),
            new ExportColumn('name', 'Product name'),
            new ExportColumn('price', 'Price', true),
        ];
    }

    /**
     * @return array<string, string> Part contents keyed by name, sorted by name
     */
    private function parts(string $contents): array
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'mago-xlsx-test');
        file_put_contents($path, $contents);
        $archive = new \ZipArchive();
        self::assertTrue($archive->open($path));
        $parts = [];
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $name = (string)$archive->getNameIndex($index);
            $parts[$name] = (string)$archive->getFromIndex($index);
        }
        $archive->close();
        unlink($path);
        ksort($parts);

        return $parts;
    }

    /**
     * Every cell of the sheet as [reference, type, style, value]
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function cells(string $contents): array
    {
        $sheet = simplexml_load_string($this->parts($contents)['xl/worksheets/sheet1.xml']);
        self::assertNotFalse($sheet);
        $sheet->registerXPathNamespace('x', self::MAIN_NAMESPACE);

        return array_map(static function (\SimpleXMLElement $cell): array {
            $cell->registerXPathNamespace('x', self::MAIN_NAMESPACE);
            $text = $cell->xpath('x:is/x:t') ?: $cell->xpath('x:v') ?: [];

            return [(string)$cell['r'], (string)$cell['t'], (string)$cell['s'], (string)($text[0] ?? '')];
        }, $sheet->xpath('//x:c') ?: []);
    }
}

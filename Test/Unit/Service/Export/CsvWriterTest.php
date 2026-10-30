<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Export;

use MagoAssistant\Mago\Api\Data\ExportColumn;
use MagoAssistant\Mago\Service\Export\CsvWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #298: the CSV download of a tool call's full result.
 */
final class CsvWriterTest extends TestCase
{
    #[Test]
    public function itStartsWithAByteOrderMarkAndTheColumnLabels(): void
    {
        $csv = (new CsvWriter())->write($this->columns(), []);

        self::assertSame("\xEF\xBB\xBFSKU,Product name,Quantity\n", $csv);
    }

    #[Test]
    public function itWritesTheRowsInColumnOrderAndLeavesOtherKeysOut(): void
    {
        $csv = (new CsvWriter())->write($this->columns(), [
            ['qty' => 3, 'name' => 'Gobi HeatTec® Tee', 'sku' => 'MS04', 'admin_url' => 'https://example.test'],
            ['sku' => 'MT01', 'name' => null, 'qty' => 0],
        ]);

        self::assertSame(['MS04,Gobi HeatTec® Tee,3', 'MT01,,0', ''], array_slice(explode("\n", $csv), 1));
    }

    #[Test]
    public function itQuotesCellsWithSeparatorsQuotesOrLineBreaks(): void
    {
        $csv = (new CsvWriter())->write($this->columns(), [['sku' => 'A,1', 'name' => "Say \"hi\"\nnow", 'qty' => 1]]);

        self::assertStringEndsWith("\"A,1\",\"Say \"\"hi\"\"\nnow\",1\n", $csv);
    }

    #[Test]
    public function itNeutralisesTextThatASpreadsheetWouldRunAsAFormulaButKeepsNegativeNumbers(): void
    {
        $csv = (new CsvWriter())->write($this->columns(), [['sku' => '=HYPERLINK("x")', 'name' => '@SUM(A1)', 'qty' => -2]]);

        self::assertStringEndsWith("\"'=HYPERLINK(\"\"x\"\")\",'@SUM(A1),-2\n", $csv);
    }

    #[Test]
    public function itDescribesItselfAsUtf8Csv(): void
    {
        $writer = new CsvWriter();

        self::assertSame('text/csv; charset=UTF-8', $writer->getContentType());
        self::assertSame('csv', $writer->getFileExtension());
    }

    /**
     * @return list<ExportColumn>
     */
    private function columns(): array
    {
        return [
            new ExportColumn('sku', 'SKU'),
            new ExportColumn('name', 'Product name'),
            new ExportColumn('qty', 'Quantity', true),
        ];
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Inventory;

use MagoAssistant\Mago\Api\Data\ExportColumn;
use MagoAssistant\Mago\Service\Export\CsvWriter;

/**
 * Turns dead stock report rows into a CSV file that spreadsheet programs open as UTF-8.
 */
class DeadStockCsv
{
    public function __construct(
        private readonly CsvWriter $csvWriter
    ) {
    }

    /**
     * Builds the CSV contents.
     *
     * @param iterable<list<array<string,mixed>>> $chunks Report rows, one list per chunk
     * @return string
     */
    public function build(iterable $chunks): string
    {
        return $this->csvWriter->write($this->columns(), $this->rows($chunks));
    }

    /**
     * The columns of a dead stock export, in order
     *
     * @return list<ExportColumn>
     */
    public function columns(): array
    {
        return [
            new ExportColumn('sku', (string)__('SKU')),
            new ExportColumn('product_name', (string)__('Product name')),
            new ExportColumn('qty', (string)__('Quantity'), true),
            new ExportColumn('units_sold', (string)__('Units sold in period'), true),
            new ExportColumn('last_sold_at', (string)__('Last sold')),
            new ExportColumn('created_at', (string)__('Created')),
            new ExportColumn('days_of_cover', (string)__('Days of cover'), true),
            new ExportColumn('unit_value', (string)__('Unit value'), true),
            new ExportColumn('value_basis', (string)__('Value basis')),
            new ExportColumn('stock_value', (string)__('Stock value'), true),
        ];
    }

    /**
     * @param iterable<list<array<string,mixed>>> $chunks
     * @return \Generator<int, array<string,mixed>>
     */
    private function rows(iterable $chunks): \Generator
    {
        foreach ($chunks as $rows) {
            yield from $rows;
        }
    }
}

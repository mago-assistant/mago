<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Inventory;

/**
 * Turns dead stock report rows into a CSV file that spreadsheet programs open as UTF-8.
 */
class DeadStockCsv
{
    private const BOM = "\xEF\xBB\xBF";

    /**
     * Characters that make a spreadsheet read a cell as a formula.
     */
    private const FORMULA_START = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Builds the CSV contents.
     *
     * @param iterable<list<array<string,mixed>>> $chunks Report rows, one list per chunk
     * @return string
     */
    public function build(iterable $chunks): string
    {
        $csv = self::BOM . $this->line(array_values(array_map('strval', $this->columns()))) . "\n";
        foreach ($chunks as $rows) {
            $csv .= $this->lines($rows);
        }

        return $csv;
    }

    /**
     * CSV lines for report rows, each ending in a newline.
     *
     * @param list<array<string,mixed>> $rows
     * @return string
     */
    public function lines(array $rows): string
    {
        $keys = array_keys($this->columns());
        $lines = '';
        foreach ($rows as $row) {
            $cells = [];
            foreach ($keys as $key) {
                $cells[] = $this->cell($row[$key] ?? '');
            }
            $lines .= $this->line($cells) . "\n";
        }

        return $lines;
    }

    /**
     * Column headers keyed by report row field.
     *
     * @return array<string,\Magento\Framework\Phrase>
     */
    private function columns(): array
    {
        return [
            'sku' => __('SKU'),
            'product_name' => __('Product name'),
            'qty' => __('Quantity'),
            'units_sold' => __('Units sold in period'),
            'last_sold_at' => __('Last sold'),
            'created_at' => __('Created'),
            'days_of_cover' => __('Days of cover'),
            'unit_value' => __('Unit value'),
            'value_basis' => __('Value basis'),
            'stock_value' => __('Stock value'),
        ];
    }

    /**
     * Joins cells into one CSV line, quoting where needed.
     *
     * @param list<string> $cells
     * @return string
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
     *
     * @param mixed $value
     * @return string
     */
    private function cell(mixed $value): string
    {
        $value = (string)$value;

        return $value !== '' && !is_numeric($value) && in_array($value[0], self::FORMULA_START, true)
            ? "'" . $value
            : $value;
    }
}

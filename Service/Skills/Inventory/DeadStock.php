<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Inventory;

use Magento\Framework\App\Config\ScopeConfigInterface;
use MagoAssistant\Mago\Api\Tool\ExportableToolInterface;
use MagoAssistant\Mago\Service\Export\ExportRefusedException;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

class DeadStock implements ExportableToolInterface
{
    public const ACL = 'Magento_Reports::report_products';

    private const BASE_CURRENCY_PATH = 'currency/options/base';
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 50;

    public function __construct(
        private readonly DeadStockReport $report,
        private readonly DeadStockCsv $csv,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function getName(): string
    {
        return 'dead_stock';
    }

    public function getDescription(): string
    {
        return 'Find dead stock among products that hold a quantity. action "obsolete": in stock and '
            . 'no net sales in the last N months (obsolete inventory). action "slow_moving": sold in '
            . 'the last N months, but the stock on hand lasts at least min_days_of_cover days at that '
            . 'sales rate (slow-moving inventory). Only products created before the period count, '
            . 'because Magento keeps no receipt date. Returns totals, the products with the highest '
            . 'stock value; the admin can download the full list from the chat. Does not change stock or '
            . 'prices. With Multi-Source Inventory the quantity is the sum over all sources.';
    }

    public function getParameterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => DeadStockReport::TYPES,
                    'description' => '"obsolete" for unsold stock, "slow_moving" for stock that sells too slowly.',
                ],
                'months' => [
                    'type' => 'integer',
                    'minimum' => DeadStockReport::MIN_MONTHS,
                    'maximum' => DeadStockReport::MAX_MONTHS,
                    'description' => 'Length of the sales period in months. Default 6.',
                ],
                'min_days_of_cover' => [
                    'type' => 'integer',
                    'minimum' => DeadStockReport::MIN_DAYS_OF_COVER,
                    'description' => 'slow_moving only: how many days the stock must last at the '
                        . 'current sales rate to count as slow-moving. Default 180.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                    'description' => 'How many products to list in the answer. Default 20. The download holds all.',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function getMagentoAcl(array $input = []): string
    {
        return self::ACL;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function isReadOnlyAction(array $input): bool
    {
        return true;
    }

    public function getFieldClassification(string $action = ''): array
    {
        return [
            'action' => [PiiClass::PUBLIC],
            'months' => [PiiClass::PUBLIC],
            'since' => [PiiClass::PUBLIC],
            'min_days_of_cover' => [PiiClass::PUBLIC],
            'currency' => [PiiClass::PUBLIC],
            'product_count' => [PiiClass::PUBLIC],
            'total_qty' => [PiiClass::PUBLIC],
            'total_stock_value' => [PiiClass::PUBLIC],
            'shown' => [PiiClass::PUBLIC],
            'sku' => [PiiClass::PUBLIC],
            'product_name' => [PiiClass::PUBLIC],
            'qty' => [PiiClass::PUBLIC],
            'units_sold' => [PiiClass::PUBLIC],
            'last_sold_at' => [PiiClass::PUBLIC],
            'created_at' => [PiiClass::PUBLIC],
            'days_of_cover' => [PiiClass::PUBLIC],
            'unit_value' => [PiiClass::PUBLIC],
            'value_basis' => [PiiClass::PUBLIC],
            'stock_value' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return 'Lead with product_count, total_qty and total_stock_value in the currency given, then '
            . 'a table of the listed products. stock_value uses cost when value_basis is "cost", '
            . 'otherwise the regular price; say so when both occur. last_sold_at null means never sold. '
            . 'When product_count exceeds shown, say the table is the top by stock value and that the full '
            . 'list can be downloaded with the CSV and Excel links under the table.';
    }

    public function execute(array $params): array
    {
        $action = (string)($params['action'] ?? '');
        if (!in_array($action, DeadStockReport::TYPES, true)) {
            return ['error' => 'dead_stock needs action "obsolete" or "slow_moving"'];
        }

        $months = $this->months($params);
        $minDaysOfCover = $this->minDaysOfCover($params);
        $limit = max(1, min(self::MAX_LIMIT, (int)($params['limit'] ?? self::DEFAULT_LIMIT)));

        $summary = $this->report->summary($action, $months, $minDaysOfCover);
        $shown = $this->report->build($action, $months, $minDaysOfCover, $limit);

        $result = [
            'action' => $action,
            'months' => $months,
            'since' => $this->report->since($months)->format('Y-m-d'),
        ];
        if ($action === DeadStockReport::SLOW_MOVING) {
            $result['min_days_of_cover'] = $minDaysOfCover;
        }

        return $result + [
            'currency' => (string)$this->scopeConfig->getValue(self::BASE_CURRENCY_PATH),
            'product_count' => $summary['product_count'],
            'total_qty' => $summary['total_qty'],
            'total_stock_value' => $summary['total_stock_value'],
            'shown' => count($shown),
            'products' => array_map(static function (array $row): array {
                unset($row['product_id']);
                return $row;
            }, $shown),
        ];
    }

    public function isExportableAction(array $input): bool
    {
        return in_array($input['action'] ?? '', DeadStockReport::TYPES, true);
    }

    public function getExportTitle(array $input): string
    {
        return ($input['action'] ?? '') === DeadStockReport::SLOW_MOVING
            ? (string)__('Full slow-moving inventory list')
            : (string)__('Full obsolete inventory list');
    }

    public function countExportRows(array $input): int
    {
        if (!$this->isExportableAction($input)) {
            throw new ExportRefusedException('dead_stock needs action "obsolete" or "slow_moving"');
        }

        return (int)$this->report->summary(
            (string)$input['action'],
            $this->months($input),
            $this->minDaysOfCover($input)
        )['product_count'];
    }

    public function getExportColumns(array $input): array
    {
        return $this->csv->columns();
    }

    public function exportRows(array $input, int $maxRows): iterable
    {
        if (!$this->isExportableAction($input)) {
            throw new ExportRefusedException('dead_stock needs action "obsolete" or "slow_moving"');
        }

        $rowCount = 0;
        $chunks = $this->report->chunks((string)$input['action'], $this->months($input), $this->minDaysOfCover($input));
        foreach ($chunks as $rows) {
            foreach ($rows as $row) {
                yield $row;
                if (++$rowCount >= $maxRows) {
                    return;
                }
            }
        }
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function months(array $params): int
    {
        return DeadStockReport::clampMonths((int)($params['months'] ?? DeadStockReport::DEFAULT_MONTHS));
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function minDaysOfCover(array $params): int
    {
        return DeadStockReport::clampMinDaysOfCover(
            (int)($params['min_days_of_cover'] ?? DeadStockReport::DEFAULT_MIN_DAYS_OF_COVER)
        );
    }
}

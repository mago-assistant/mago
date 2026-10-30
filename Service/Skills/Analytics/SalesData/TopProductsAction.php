<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Analytics\SalesData;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use MagoAssistant\Mago\Api\Data\ExportColumn;
use MagoAssistant\Mago\Api\Skill\ExportableActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Skills\PeriodParser;
use MagoAssistant\Mago\Service\Skills\ResultLimit;

class TopProductsAction implements ExportableActionInterface
{
    private const DEFAULT_PERIOD = '30days';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly PeriodParser $periodParser
    ) {
    }

    public function getName(): string
    {
        return 'top_products';
    }

    public function getDescription(): string
    {
        return 'Best-selling products';
    }

    public function getParameterSchema(): array
    {
        return [
            'period' => [
                'type' => 'string',
                'description' => 'Time period: "today", "yesterday", "7days", "30days", "this_month", "last_month", "this_year", "all" for no lower bound, "YYYY-MM" for a specific month, or "YYYY-MM-DD:YYYY-MM-DD" for a custom range',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Number of results to return (default: 10, max: ' . ResultLimit::MAX . ')',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return 'Magento_Sales::sales';
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function getFieldClassification(): array
    {
        return [
            'period' => [PiiClass::PUBLIC],
            'sku' => [PiiClass::PUBLIC],
            'name' => [PiiClass::PUBLIC],
            'qty_ordered' => [PiiClass::PUBLIC],
            'revenue' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $period = (string)($params['period'] ?? self::DEFAULT_PERIOD);
        $rows = $this->resourceConnection->getConnection()->fetchAll(
            $this->select($period, ResultLimit::fromParams($params, 10))
        );

        return ['period' => $period, 'top_products' => array_map($this->row(...), $rows)];
    }

    public function getExportTitle(array $params): string
    {
        return (string)__('Full best-seller list');
    }

    public function countExportRows(array $params, int $adminUserId): int
    {
        $connection = $this->resourceConnection->getConnection();
        $products = $this->select((string)($params['period'] ?? self::DEFAULT_PERIOD), 1)
            ->reset(Select::LIMIT_COUNT)
            ->reset(Select::LIMIT_OFFSET)
            ->reset(Select::ORDER);

        return (int)$connection->fetchOne($connection->select()->from(['products' => $products], ['COUNT(*)']));
    }

    public function getExportColumns(): array
    {
        return [
            new ExportColumn('sku', (string)__('SKU')),
            new ExportColumn('name', (string)__('Product name')),
            new ExportColumn('qty_ordered', (string)__('Quantity ordered'), true),
            new ExportColumn('revenue', (string)__('Revenue'), true),
        ];
    }

    public function exportRows(array $params, int $adminUserId, int $maxRows): iterable
    {
        $statement = $this->resourceConnection->getConnection()->query(
            $this->select((string)($params['period'] ?? self::DEFAULT_PERIOD), max(1, $maxRows))
        );
        while (is_array($row = $statement->fetch(\Zend_Db::FETCH_ASSOC))) {
            yield $this->row($row);
        }
    }

    /**
     * The products of the period, most first; the SKU breaks ties so the order is stable.
     */
    private function select(string $period, int $limit): Select
    {
        [$from, $to] = $this->periodParser->parse($period);

        return $this->resourceConnection->getConnection()->select()
            ->from(['oi' => $this->resourceConnection->getTableName('sales_order_item')], [
                'sku' => 'oi.sku',
                'name' => 'oi.name',
                'qty_ordered' => new Expression('SUM(oi.qty_ordered)'),
                'revenue' => new Expression('SUM(oi.row_total)'),
            ])
            ->join(['o' => $this->resourceConnection->getTableName('sales_order')], 'o.entity_id = oi.order_id', [])
            ->where('o.created_at >= ?', $from)
            ->where('o.created_at <= ?', $to)
            ->where('o.state NOT IN (?)', ['canceled', 'closed'])
            ->where('oi.parent_item_id IS NULL')
            ->group('oi.sku')
            ->order(['qty_ordered DESC', 'oi.sku ASC'])
            ->limit($limit);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string|int|float>
     */
    private function row(array $row): array
    {
        return [
            'sku' => (string)$row['sku'],
            'name' => (string)$row['name'],
            'qty_ordered' => (int)$row['qty_ordered'],
            'revenue' => round((float)$row['revenue'], 2),
        ];
    }
}

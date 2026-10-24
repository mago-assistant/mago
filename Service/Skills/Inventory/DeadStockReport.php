<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Inventory;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Model\Stock;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Sales\Model\Order;

/**
 * Finds stocked products that did not sell (obsolete) or sell too slowly for their quantity
 * (slow-moving) within a period. Magento keeps no receipt date, so a product counts as held for the
 * whole period when it was created before the period started.
 *
 * @phpstan-type DeadStockRow array{product_id:int,sku:string,product_name:string,qty:float,
 *     units_sold:float,last_sold_at:?string,created_at:string,days_of_cover:?int,unit_value:float,
 *     value_basis:string,stock_value:float}
 * @phpstan-type DeadStockSummary array{product_count:int,total_qty:float,total_stock_value:float}
 */
class DeadStockReport
{
    public const OBSOLETE = 'obsolete';
    public const SLOW_MOVING = 'slow_moving';
    public const TYPES = [self::OBSOLETE, self::SLOW_MOVING];

    public const VALUE_BASIS_COST = 'cost';
    public const VALUE_BASIS_PRICE = 'price';

    public const DEFAULT_MONTHS = 6;
    public const MIN_MONTHS = 1;
    public const MAX_MONTHS = 36;
    public const DEFAULT_MIN_DAYS_OF_COVER = 180;
    public const MIN_DAYS_OF_COVER = 1;

    /** Rows handed to the CSV writer at a time. */
    public const EXPORT_CHUNK_SIZE = 1000;

    /** Product types that hold a quantity of their own. */
    private const STOCKED_TYPES = ['simple', 'virtual', 'downloadable'];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly MsiAvailability $msiAvailability,
        private readonly StockConfigurationInterface $stockConfiguration,
        private readonly EavConfig $eavConfig,
        private readonly MetadataPool $metadataPool
    ) {
    }

    public static function clampMonths(int $months): int
    {
        return max(self::MIN_MONTHS, min(self::MAX_MONTHS, $months));
    }

    public static function clampMinDaysOfCover(int $minDaysOfCover): int
    {
        return max(self::MIN_DAYS_OF_COVER, $minDaysOfCover);
    }

    /**
     * Days the quantity lasts at the sales rate of the period, null when nothing sold.
     */
    public static function daysOfCover(float $qty, float $unitsSold, int $periodDays): ?int
    {
        return $unitsSold > 0 ? (int)round($qty * max(1, $periodDays) / $unitsSold) : null;
    }

    /**
     * The products with the highest stock value first.
     *
     * @param string $type One of self::TYPES
     * @param int $months Length of the sales period, clamped to MIN_MONTHS..MAX_MONTHS
     * @param int $minDaysOfCover Slow-moving threshold, ignored for obsolete
     * @param int $limit Maximum number of rows
     * @return list<DeadStockRow>
     */
    public function build(
        string $type,
        int $months,
        int $minDaysOfCover = self::DEFAULT_MIN_DAYS_OF_COVER,
        int $limit = 20
    ): array {
        $period = $this->period($months);
        $select = $this->candidates($type, $period['since'], $period['days'], $minDaysOfCover)
            ->order(['stock_value DESC', 'e.entity_id ASC'])
            ->limit(max(1, $limit));

        $connection = $this->resource->getConnection();
        $dbRows = $connection->fetchAll($select);
        $lastSold = $this->lastSold(array_values(array_map(
            static fn (array $row): int => (int)$row['product_id'],
            $dbRows
        )));

        $rows = [];
        foreach ($dbRows as $row) {
            $row['last_sold_at'] = $lastSold[(int)$row['product_id']] ?? null;
            $rows[] = $this->toRow($row, $period['days']);
        }

        return $rows;
    }

    /**
     * Count, quantity and stock value over all matching products.
     *
     * @return DeadStockSummary
     */
    public function summary(
        string $type,
        int $months,
        int $minDaysOfCover = self::DEFAULT_MIN_DAYS_OF_COVER
    ): array {
        $period = $this->period($months);
        $connection = $this->resource->getConnection();
        $summary = $connection->select()
            ->from(['t' => $this->candidates($type, $period['since'], $period['days'], $minDaysOfCover)], [
                'product_count' => new \Zend_Db_Expr('COUNT(*)'),
                'total_qty' => new \Zend_Db_Expr('COALESCE(SUM(t.qty), 0)'),
                'total_stock_value' => new \Zend_Db_Expr('COALESCE(SUM(t.stock_value), 0)'),
            ]);
        $row = $connection->fetchRow($summary) ?: [];

        return [
            'product_count' => (int)($row['product_count'] ?? 0),
            'total_qty' => (float)($row['total_qty'] ?? 0),
            'total_stock_value' => round((float)($row['total_stock_value'] ?? 0), 2),
        ];
    }

    /**
     * All matching products, highest stock value first, in chunks for the CSV export. The query runs
     * once; the last sale over the whole order history is aggregated in the same pass.
     *
     * @return \Generator<int, list<DeadStockRow>>
     */
    public function chunks(
        string $type,
        int $months,
        int $minDaysOfCover = self::DEFAULT_MIN_DAYS_OF_COVER,
        int $chunkSize = self::EXPORT_CHUNK_SIZE
    ): \Generator {
        $period = $this->period($months);
        $select = $this->candidates($type, $period['since'], $period['days'], $minDaysOfCover)
            ->joinLeft(['l' => $this->lastSoldSelect()], 'l.product_id = e.entity_id', ['last_sold_at'])
            ->order(['stock_value DESC', 'e.entity_id ASC']);

        $statement = $this->resource->getConnection()->query($select);
        $chunk = [];
        while (is_array($row = $statement->fetch(\Zend_Db::FETCH_ASSOC))) {
            $chunk[] = $this->toRow($row, $period['days']);
            if (count($chunk) >= max(1, $chunkSize)) {
                yield $chunk;
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            yield $chunk;
        }
    }

    /**
     * Start of the period: the same day N months back, or the last day of that month when it is
     * shorter, so 31 March minus one month is 28 February rather than 3 March.
     */
    public function since(int $months, ?\DateTimeImmutable $today = null): \DateTimeImmutable
    {
        $today = ($today ?? new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->setTime(0, 0);
        $firstOfMonth = $today->modify('first day of this month')->modify(sprintf('-%d months', $months));

        return $firstOfMonth->setDate(
            (int)$firstOfMonth->format('Y'),
            (int)$firstOfMonth->format('n'),
            min((int)$today->format('j'), (int)$firstOfMonth->format('t'))
        );
    }

    /**
     * @return array{since:\DateTimeImmutable,days:int}
     */
    private function period(int $months): array
    {
        $since = $this->since(self::clampMonths($months));
        $days = (int)(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->diff($since)->days;

        return ['since' => $since, 'days' => max(1, $days)];
    }

    private function candidates(string $type, \DateTimeImmutable $since, int $periodDays, int $minDaysOfCover): Select
    {
        $connection = $this->resource->getConnection();
        $sinceSql = $since->format('Y-m-d H:i:s');
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();

        $select = $connection->select()
            ->from(
                ['e' => $this->resource->getTableName('catalog_product_entity')],
                ['product_id' => 'entity_id', 'sku', 'created_at']
            )
            ->join(
                ['si' => $this->resource->getTableName('cataloginventory_stock_item')],
                $connection->quoteInto('si.product_id = e.entity_id AND si.stock_id = ?', Stock::DEFAULT_STOCK_ID),
                []
            )
            ->joinLeft(
                ['s' => $this->soldSelect($sinceSql)],
                's.product_id = e.entity_id',
                ['units_sold' => new \Zend_Db_Expr('COALESCE(s.units_sold, 0)')]
            )
            ->where('e.type_id IN (?)', self::STOCKED_TYPES)
            ->where('e.created_at < ?', $sinceSql)
            ->where($this->manageStockCondition());

        $qty = $this->joinQuantity($select);
        $name = $this->joinAttribute($select, 'name', $linkField);
        $cost = $this->joinAttribute($select, 'cost', $linkField);
        $price = $this->joinAttribute($select, 'price', $linkField);

        $hasCost = sprintf('COALESCE(%s, 0) > 0', $cost);
        $unitValue = sprintf('CASE WHEN %s THEN %s ELSE COALESCE(%s, 0) END', $hasCost, $cost, $price);
        $select->columns([
            'qty' => new \Zend_Db_Expr($qty),
            'product_name' => new \Zend_Db_Expr(sprintf('COALESCE(%s, \'\')', $name)),
            'unit_value' => new \Zend_Db_Expr($unitValue),
            'value_basis' => new \Zend_Db_Expr(sprintf(
                'CASE WHEN %s THEN %s ELSE %s END',
                $hasCost,
                $connection->quote(self::VALUE_BASIS_COST),
                $connection->quote(self::VALUE_BASIS_PRICE)
            )),
            'stock_value' => new \Zend_Db_Expr(sprintf('ROUND((%s) * %s, 2)', $unitValue, $qty)),
        ]);
        $select->where($qty . ' > 0');

        if ($type === self::OBSOLETE) {
            $select->where('COALESCE(s.units_sold, 0) <= 0');
        } else {
            $select->where('s.units_sold > 0')
                ->where(sprintf(
                    '%s * %d >= %d * s.units_sold',
                    $qty,
                    $periodDays,
                    self::clampMinDaysOfCover($minDaysOfCover)
                ));
        }

        return $select;
    }

    /**
     * Joins the physical quantity: the sum over all sources with MSI, the legacy stock item without.
     *
     * @return string SQL expression for the quantity
     */
    private function joinQuantity(Select $select): string
    {
        if (!$this->msiAvailability->isEnabled()) {
            return 'si.qty';
        }

        $sourceItems = $this->resource->getConnection()->select()
            ->from(
                ['isi' => $this->resource->getTableName('inventory_source_item')],
                ['sku', 'qty' => new \Zend_Db_Expr('SUM(isi.quantity)')]
            )
            ->group('isi.sku');
        $select->joinLeft(['q' => $sourceItems], 'q.sku = e.sku', []);

        return 'COALESCE(q.qty, 0)';
    }

    /**
     * Joins the admin store view value of a product attribute.
     *
     * @return string SQL expression for the value, NULL when the attribute does not exist
     */
    private function joinAttribute(Select $select, string $code, string $linkField): string
    {
        $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);
        $attributeId = (int)$attribute->getAttributeId();
        if ($attributeId === 0) {
            return 'NULL';
        }

        $alias = 'attr_' . $code;
        $select->joinLeft(
            [$alias => $attribute->getBackendTable()],
            $this->resource->getConnection()->quoteInto(
                sprintf('%1$s.%2$s = e.%2$s AND %1$s.store_id = 0 AND %1$s.attribute_id = ?', $alias, $linkField),
                $attributeId
            ),
            []
        );

        return $alias . '.value';
    }

    /**
     * A product's own manage stock setting, or the store setting when it uses the config value.
     */
    private function manageStockCondition(): string
    {
        return $this->stockConfiguration->getManageStock()
            ? '(si.use_config_manage_stock = 1 OR si.manage_stock = 1)'
            : '(si.use_config_manage_stock = 0 AND si.manage_stock = 1)';
    }

    /**
     * Net units sold per product since the given date.
     *
     * Child rows of configurable and bundle orders carry the simple product id, so they are counted
     * and parent rows are skipped by the product type filter. Magento records cancellations, and for
     * some product types refunds, on the parent row only, so the child is netted by the larger of its
     * own and its parent's cancelled and refunded share.
     */
    private function soldSelect(string $sinceSql): Select
    {
        $connection = $this->resource->getConnection();
        $orderItem = $this->resource->getTableName('sales_order_item');

        return $connection->select()
            ->from(['oi' => $orderItem], [
                'product_id',
                'units_sold' => new \Zend_Db_Expr(
                    'SUM(oi.qty_ordered - GREATEST('
                    . 'COALESCE(oi.qty_canceled, 0) + COALESCE(oi.qty_refunded, 0), '
                    . 'COALESCE(oi.qty_ordered * (COALESCE(p.qty_canceled, 0) + COALESCE(p.qty_refunded, 0))'
                    . ' / NULLIF(p.qty_ordered, 0), 0)))'
                ),
            ])
            ->join(['o' => $this->resource->getTableName('sales_order')], 'o.entity_id = oi.order_id', [])
            ->joinLeft(['p' => $orderItem], 'p.item_id = oi.parent_item_id', [])
            ->where('o.state <> ?', Order::STATE_CANCELED)
            ->where('o.created_at >= ?', $sinceSql)
            ->where('oi.product_type IN (?)', self::STOCKED_TYPES)
            ->group('oi.product_id');
    }

    /**
     * Last order date per product over the whole order history.
     *
     * @param list<int>|null $productIds Only these products, all stocked products when null
     */
    private function lastSoldSelect(?array $productIds = null): Select
    {
        $select = $this->resource->getConnection()->select()
            ->from(
                ['oi' => $this->resource->getTableName('sales_order_item')],
                ['product_id', 'last_sold_at' => new \Zend_Db_Expr('MAX(o.created_at)')]
            )
            ->join(['o' => $this->resource->getTableName('sales_order')], 'o.entity_id = oi.order_id', [])
            ->where('o.state <> ?', Order::STATE_CANCELED)
            ->where('oi.product_type IN (?)', self::STOCKED_TYPES)
            ->group('oi.product_id');

        if ($productIds !== null) {
            $select->where('oi.product_id IN (?)', $productIds);
        }

        return $select;
    }

    /**
     * @param list<int> $productIds
     * @return array<int,string>
     */
    private function lastSold(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $lastSold = [];
        foreach ($this->resource->getConnection()->fetchPairs($this->lastSoldSelect($productIds)) as $id => $date) {
            $lastSold[(int)$id] = (string)$date;
        }

        return $lastSold;
    }

    /**
     * @param array<string,mixed> $row
     * @return DeadStockRow
     */
    private function toRow(array $row, int $periodDays): array
    {
        $qty = (float)$row['qty'];
        $unitsSold = (float)$row['units_sold'];

        return [
            'product_id' => (int)$row['product_id'],
            'sku' => (string)$row['sku'],
            'product_name' => (string)$row['product_name'],
            'qty' => $qty,
            'units_sold' => $unitsSold,
            'last_sold_at' => isset($row['last_sold_at']) ? (string)$row['last_sold_at'] : null,
            'created_at' => (string)$row['created_at'],
            'days_of_cover' => self::daysOfCover($qty, $unitsSold, $periodDays),
            'unit_value' => (float)$row['unit_value'],
            'value_basis' => (string)$row['value_basis'] === self::VALUE_BASIS_COST
                ? self::VALUE_BASIS_COST
                : self::VALUE_BASIS_PRICE,
            'stock_value' => (float)$row['stock_value'],
        ];
    }
}

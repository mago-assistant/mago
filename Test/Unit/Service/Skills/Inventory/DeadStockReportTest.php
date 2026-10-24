<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Inventory;

use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Platform\Quote;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Select\ColumnsRenderer;
use Magento\Framework\DB\Select\DistinctRenderer;
use Magento\Framework\DB\Select\FromRenderer;
use Magento\Framework\DB\Select\GroupRenderer;
use Magento\Framework\DB\Select\LimitRenderer;
use Magento\Framework\DB\Select\OrderRenderer;
use Magento\Framework\DB\Select\SelectRenderer;
use Magento\Framework\DB\Select\WhereRenderer;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Module\Manager as ModuleManager;
use MagoAssistant\Mago\Service\Skills\Inventory\DeadStockReport;
use MagoAssistant\Mago\Service\Skills\Inventory\MsiAvailability;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The database adapter is stubbed, but the selects are rendered by Magento's own renderers, so the
 * tests read the SQL the report would send.
 */
final class DeadStockReportTest extends TestCase
{
    /** @var list<string> SQL of every query, in order */
    private array $queries = [];

    /** @var list<array<string,mixed>> */
    private array $fetchAll = [];

    /** @var array<int|string,string> */
    private array $fetchPairs = [];

    /** @var array<string,mixed>|false */
    private array|false $fetchRow = false;

    #[Test]
    public function itListsObsoleteStockFromTheLegacyStockItemSortedAndLimitedInSql(): void
    {
        $this->report()->build(DeadStockReport::OBSOLETE, 6, 180, 20);

        $sql = $this->queries[0];
        self::assertStringContainsString('si.qty > 0', $sql);
        self::assertStringContainsString('COALESCE(s.units_sold, 0) <= 0', $sql);
        self::assertStringContainsString('ORDER BY `stock_value` DESC, `e`.`entity_id` ASC', $sql);
        self::assertStringEndsWith(' LIMIT 20', $sql);
        self::assertStringNotContainsString('inventory_source_item', $sql);
    }

    #[Test]
    public function itSumsTheQuantityOfAllSourcesWhenMsiIsEnabled(): void
    {
        $this->report(msi: true)->build(DeadStockReport::OBSOLETE, 6);

        self::assertStringContainsString('SUM(isi.quantity)', $this->queries[0]);
        self::assertStringContainsString('COALESCE(q.qty, 0) > 0', $this->queries[0]);
        self::assertStringNotContainsString('si.qty > 0', $this->queries[0]);
    }

    #[Test]
    public function itCountsProductsOnTheStoreSettingOnlyWhenTheStoreManagesStock(): void
    {
        $this->report(manageStock: true)->build(DeadStockReport::OBSOLETE, 6);
        $this->report(manageStock: false)->build(DeadStockReport::OBSOLETE, 6);

        self::assertStringContainsString('(si.use_config_manage_stock = 1 OR si.manage_stock = 1)', $this->queries[0]);
        self::assertStringContainsString('(si.use_config_manage_stock = 0 AND si.manage_stock = 1)', $this->queries[1]);
    }

    #[Test]
    public function itKeepsSlowMovingStockThatLastsAtLeastTheCoverThreshold(): void
    {
        $this->report()->build(DeadStockReport::SLOW_MOVING, 6, 365);

        self::assertStringContainsString('s.units_sold > 0', $this->queries[0]);
        self::assertMatchesRegularExpression('/si\.qty \* \d+ >= 365 \* s\.units_sold/', $this->queries[0]);
    }

    #[Test]
    public function itNetsChildOrderRowsByTheCancelledAndRefundedShareOfTheirParent(): void
    {
        $this->report()->build(DeadStockReport::OBSOLETE, 6);

        self::assertStringContainsString(
            'LEFT JOIN `sales_order_item` AS `p` ON p.item_id = oi.parent_item_id',
            $this->queries[0]
        );
        self::assertStringContainsString('GREATEST(', $this->queries[0]);
        self::assertStringContainsString('/ NULLIF(p.qty_ordered, 0)', $this->queries[0]);
    }

    #[Test]
    public function itValuesStockAtCostWhenSetAndAtPriceOtherwise(): void
    {
        $this->report()->build(DeadStockReport::OBSOLETE, 6);

        self::assertStringContainsString(
            "CASE WHEN COALESCE(attr_cost.value, 0) > 0 THEN 'cost' ELSE 'price' END",
            $this->queries[0]
        );
        self::assertStringContainsString('attr_price.store_id = 0', $this->queries[0]);
    }

    #[Test]
    public function itLooksUpTheLastSaleOnlyForTheListedProducts(): void
    {
        $this->fetchAll = [$this->dbRow(5, 'cost'), $this->dbRow(9, 'price')];
        $this->fetchPairs = ['5' => '2025-01-02 10:00:00'];

        $rows = $this->report()->build(DeadStockReport::OBSOLETE, 6);

        self::assertStringContainsString('oi.product_id IN (5, 9)', $this->queries[1]);
        self::assertSame([
            'product_id' => 5,
            'sku' => 'SKU-5',
            'product_name' => 'Product 5',
            'qty' => 90.0,
            'units_sold' => 30.0,
            'last_sold_at' => '2025-01-02 10:00:00',
            'created_at' => '2024-01-01 00:00:00',
            'days_of_cover' => DeadStockReport::daysOfCover(90.0, 30.0, $this->periodDays(6)),
            'unit_value' => 2.5,
            'value_basis' => 'cost',
            'stock_value' => 225.0,
        ], $rows[0]);
        self::assertNull($rows[1]['last_sold_at']);
        self::assertSame('price', $rows[1]['value_basis']);
    }

    #[Test]
    public function itSkipsTheLastSaleQueryWhenNothingMatches(): void
    {
        self::assertSame([], $this->report()->build(DeadStockReport::OBSOLETE, 6));
        self::assertCount(1, $this->queries);
    }

    #[Test]
    public function itTotalsOverAllMatchingProducts(): void
    {
        $this->fetchRow = ['product_count' => '3', 'total_qty' => '47.0000', 'total_stock_value' => '1410.004'];

        $summary = $this->report()->summary(DeadStockReport::OBSOLETE, 6);

        self::assertSame(['product_count' => 3, 'total_qty' => 47.0, 'total_stock_value' => 1410.0], $summary);
        self::assertStringContainsString('COUNT(*)', $this->queries[0]);
        self::assertStringNotContainsString('LIMIT', $this->queries[0]);
    }

    #[Test]
    public function itExportsInChunksFromOneQuery(): void
    {
        $this->fetchAll = [$this->dbRow(1, 'price'), $this->dbRow(2, 'price'), $this->dbRow(3, 'price')];

        $chunks = iterator_to_array($this->report()->chunks(DeadStockReport::SLOW_MOVING, 6, 180, 2), false);

        self::assertSame([[1, 2], [3]], array_map(
            static fn (array $chunk): array => array_column($chunk, 'product_id'),
            $chunks
        ));
        self::assertCount(1, $this->queries);
        self::assertStringContainsString('MAX(o.created_at)', $this->queries[0]);
        self::assertStringNotContainsString('LIMIT', $this->queries[0]);
    }

    #[Test]
    #[DataProvider('monthEnds')]
    public function itStartsThePeriodOnTheSameDayOrTheLastDayOfAShorterMonth(
        string $today,
        int $months,
        string $since
    ): void {
        self::assertSame(
            $since,
            $this->report()->since($months, new \DateTimeImmutable($today))->format('Y-m-d H:i:s')
        );
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function monthEnds(): array
    {
        return [
            '31 March minus one month' => ['2026-03-31 15:00:00', 1, '2026-02-28 00:00:00'],
            'leap year' => ['2024-03-31', 1, '2024-02-29 00:00:00'],
            'across a year' => ['2026-01-15', 3, '2025-10-15 00:00:00'],
            '31 August minus six months' => ['2026-08-31', 6, '2026-02-28 00:00:00'],
            'a 31-day month' => ['2026-05-31', 2, '2026-03-31 00:00:00'],
        ];
    }

    #[Test]
    public function itClampsMonthsAndCoverDays(): void
    {
        self::assertSame(DeadStockReport::MIN_MONTHS, DeadStockReport::clampMonths(0));
        self::assertSame(DeadStockReport::MAX_MONTHS, DeadStockReport::clampMonths(99));
        self::assertSame(12, DeadStockReport::clampMonths(12));
        self::assertSame(DeadStockReport::MIN_DAYS_OF_COVER, DeadStockReport::clampMinDaysOfCover(-5));
        self::assertSame(400, DeadStockReport::clampMinDaysOfCover(400));
    }

    #[Test]
    public function itCountsDaysOfCoverOnlyForProductsThatSold(): void
    {
        self::assertSame(270, DeadStockReport::daysOfCover(90.0, 30.0, 90));
        self::assertNull(DeadStockReport::daysOfCover(90.0, 0.0, 90));
        self::assertNull(DeadStockReport::daysOfCover(90.0, -2.0, 90));
    }

    private function periodDays(int $months): int
    {
        $since = $this->report()->since($months);

        return max(1, (int)(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->diff($since)->days);
    }

    /**
     * @return array<string,mixed>
     */
    private function dbRow(int $id, string $valueBasis): array
    {
        return [
            'product_id' => (string)$id,
            'sku' => 'SKU-' . $id,
            'created_at' => '2024-01-01 00:00:00',
            'units_sold' => '30.0000',
            'qty' => '90.0000',
            'product_name' => 'Product ' . $id,
            'unit_value' => '2.500000',
            'value_basis' => $valueBasis,
            'stock_value' => '225.00',
        ];
    }

    private function report(bool $msi = false, bool $manageStock = true): DeadStockReport
    {
        $adapter = $this->createStub(Mysql::class);
        $renderer = $this->selectRenderer();
        $adapter->method('select')->willReturnCallback(static fn (): Select => new Select($adapter, $renderer));
        $adapter->method('quote')->willReturnCallback(static fn (mixed $value): string => is_array($value)
            ? implode(', ', array_map(static fn (mixed $v): string => is_int($v) ? (string)$v : "'" . $v . "'", $value))
            : (is_int($value) ? (string)$value : "'" . $value . "'"));
        $adapter->method('quoteInto')->willReturnCallback(
            static fn (string $text, mixed $value): string => str_replace('?', $adapter->quote($value), $text)
        );
        $adapter->method('limit')->willReturnCallback(
            static fn (string $sql, int $count, int $offset = 0): string => $sql . ' LIMIT ' . $count
        );
        $adapter->method('fetchAll')->willReturnCallback(function (Select $select): array {
            $this->queries[] = (string)$select;
            return $this->fetchAll;
        });
        $adapter->method('fetchPairs')->willReturnCallback(function (Select $select): array {
            $this->queries[] = (string)$select;
            return $this->fetchPairs;
        });
        $adapter->method('fetchRow')->willReturnCallback(function (Select $select): array|false {
            $this->queries[] = (string)$select;
            return $this->fetchRow;
        });
        $adapter->method('query')->willReturnCallback(function (Select $select): \Zend_Db_Statement_Interface {
            $this->queries[] = (string)$select;
            $statement = $this->createStub(\Zend_Db_Statement_Interface::class);
            $statement->method('fetch')->willReturnOnConsecutiveCalls(...[...$this->fetchAll, false]);
            return $statement;
        });

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($adapter);
        $resource->method('getTableName')->willReturnArgument(0);

        $modules = $this->createStub(ModuleManager::class);
        $modules->method('isEnabled')->willReturn($msi);

        $stockConfiguration = $this->createStub(StockConfigurationInterface::class);
        $stockConfiguration->method('getManageStock')->willReturn($manageStock);

        $eavConfig = $this->createStub(EavConfig::class);
        $eavConfig->method('getAttribute')->willReturnCallback(function (string $entity, string $code) {
            $attribute = $this->createStub(AbstractAttribute::class);
            $attribute->method('getAttributeId')->willReturn(['name' => 73, 'cost' => 81, 'price' => 77][$code]);
            $attribute->method('getBackendTable')->willReturn(
                $code === 'name' ? 'catalog_product_entity_varchar' : 'catalog_product_entity_decimal'
            );
            return $attribute;
        });

        $metadata = $this->createStub(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn('entity_id');
        $metadataPool = $this->createStub(MetadataPool::class);
        $metadataPool->method('getMetadata')->willReturn($metadata);

        return new DeadStockReport(
            $resource,
            new MsiAvailability($modules),
            $stockConfiguration,
            $eavConfig,
            $metadataPool
        );
    }

    private function selectRenderer(): SelectRenderer
    {
        $quote = new Quote();
        $parts = [
            ['distinct', new DistinctRenderer()],
            ['columns', new ColumnsRenderer($quote)],
            ['from', new FromRenderer($quote)],
            ['where', new WhereRenderer()],
            ['group', new GroupRenderer($quote)],
            ['order', new OrderRenderer($quote)],
            ['limitcount', new LimitRenderer()],
        ];
        $renderers = [];
        foreach ($parts as $sort => [$part, $renderer]) {
            $renderers[] = ['renderer' => $renderer, 'sort' => ($sort + 1) * 100, 'part' => $part];
        }

        return new SelectRenderer($renderers);
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Catalog\CategoryData;

use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Platform\Quote;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Select\ColumnsRenderer;
use Magento\Framework\DB\Select\FromRenderer;
use Magento\Framework\DB\Select\GroupRenderer;
use Magento\Framework\DB\Select\OrderRenderer;
use Magento\Framework\DB\Select\SelectRenderer;
use Magento\Framework\DB\Select\WhereRenderer;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use MagoAssistant\Mago\Service\Skills\Catalog\CategoryData\EmptyCategoriesReport;
use MagoAssistant\Mago\Service\Skills\Catalog\CategoryData\SalableProducts;
use MagoAssistant\Mago\Service\Skills\Inventory\MsiAvailability;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EmptyCategoriesReportTest extends TestCase
{
    private const ROOT = 2;

    /** @var list<string> */
    private array $queries = [];

    /** @var bool Whether the store view has its own product status or visibility values */
    private bool $storeOverrides = true;

    /** @var list<array<string,int>> Rows the live count query returns */
    private array $liveRows = [];

    #[Test]
    public function itReportsAnEnabledCategoryWithNothingAssigned(): void
    {
        $rows = EmptyCategoriesReport::emptyCategories(
            [10 => $this->node('1/2/10', 'Sale')],
            [],
            [],
            [],
            self::ROOT
        );

        self::assertCount(1, $rows);
        self::assertSame(10, $rows[0]['category_id']);
        self::assertSame('Sale', $rows[0]['path']);
        self::assertSame(EmptyCategoriesReport::REASON_NONE_ASSIGNED, $rows[0]['reason']);
    }

    #[Test]
    public function itReportsACategoryWhoseAssignedProductsAreNotLive(): void
    {
        $rows = EmptyCategoriesReport::emptyCategories(
            [10 => $this->node('1/2/10', 'Sale')],
            [],
            [],
            [10 => 4],
            self::ROOT
        );

        self::assertSame(EmptyCategoriesReport::REASON_NONE_LIVE, $rows[0]['reason']);
        self::assertSame(4, $rows[0]['assigned_products']);
    }

    #[Test]
    public function aStaticBlockCategoryIsReportedWithItsOwnReason(): void
    {
        $rows = EmptyCategoriesReport::emptyCategories(
            [10 => ['display_mode' => 'PAGE'] + $this->node('1/2/10', 'About us')],
            [],
            [],
            [],
            self::ROOT
        );

        self::assertSame(EmptyCategoriesReport::REASON_STATIC_BLOCK, $rows[0]['reason']);
    }

    /**
     * With out-of-stock products hidden, live products that are all out of stock leave the category
     * empty, and an anchor parent of such a category is empty for the same reason.
     */
    #[Test]
    public function liveProductsThatAreAllOutOfStockGiveTheirOwnReason(): void
    {
        $rows = EmptyCategoriesReport::emptyCategories(
            [
                10 => $this->node('1/2/10', 'Men'),
                11 => $this->node('1/2/10/11', 'Shoes'),
                12 => $this->node('1/2/10/12', 'Socks'),
            ],
            [11 => 2, 12 => 1],
            [11 => 0, 12 => 0],
            [11 => 2, 12 => 1],
            self::ROOT
        );

        self::assertSame([10, 11, 12], array_column($rows, 'category_id'));
        self::assertSame(
            array_fill(0, 3, EmptyCategoriesReport::REASON_OUT_OF_STOCK),
            array_column($rows, 'reason')
        );
    }

    #[Test]
    public function oneInStockProductBelowAnAnchorKeepsItShown(): void
    {
        $rows = EmptyCategoriesReport::emptyCategories(
            [
                10 => $this->node('1/2/10', 'Men'),
                11 => $this->node('1/2/10/11', 'Shoes'),
                12 => $this->node('1/2/10/12', 'Socks'),
            ],
            [11 => 2, 12 => 1],
            [11 => 0, 12 => 1],
            [],
            self::ROOT
        );

        self::assertSame([11], array_column($rows, 'category_id'));
    }

    /**
     * The assigned counts depend on the root only, so store views sharing a root read them once.
     */
    #[Test]
    public function assignedCountsAreReadOncePerRoot(): void
    {
        $report = $this->report();
        $report->forStore(1, 1, self::ROOT);
        $report->forStore(3, 2, self::ROOT);

        self::assertCount(1, $this->queriesContaining('COUNT(*) AS `assigned`'));
        self::assertCount(2, $this->queriesContaining('AS `live`'));
    }

    /**
     * Without store view values for status and visibility a store view shows what the default scope
     * shows, so store views of one website share the live count, read on the default scope.
     */
    #[Test]
    public function storeViewsWithoutOverridesShareTheLiveCount(): void
    {
        $this->storeOverrides = false;
        $report = $this->report();
        $report->forStore(1, 1, self::ROOT);
        $report->forStore(3, 1, self::ROOT);

        $live = $this->queriesContaining('AS `live`');
        self::assertCount(1, $live);
        self::assertStringContainsString('status_s.store_id = 0', $live[0]);
    }

    #[Test]
    public function itSkipsDisabledCategoriesAndCategoriesWithLiveProducts(): void
    {
        $rows = EmptyCategoriesReport::emptyCategories(
            [
                10 => $this->node('1/2/10', 'Hidden', active: false),
                11 => $this->node('1/2/11', 'Shoes'),
            ],
            [11 => 3],
            [11 => 3],
            [11 => 3],
            self::ROOT
        );

        self::assertSame([], $rows);
    }

    /**
     * The anchor indexer shows the products of enabled subcategories in the parent; a non-anchor
     * parent shows its own products only, and a disabled child adds nothing to an anchor parent.
     */
    #[Test]
    public function anAnchorParentCountsProductsOfItsEnabledSubcategories(): void
    {
        $categories = [
            10 => $this->node('1/2/10', 'Men', anchor: true),
            11 => $this->node('1/2/10/11', 'Shoes'),
            20 => $this->node('1/2/20', 'Women', anchor: false),
            21 => $this->node('1/2/20/21', 'Bags'),
            30 => $this->node('1/2/30', 'Kids', anchor: true),
            31 => $this->node('1/2/30/31', 'Toys', active: false),
        ];

        $live = [11 => 2, 21 => 2, 31 => 2];
        $rows = EmptyCategoriesReport::emptyCategories($categories, $live, $live, [], self::ROOT);

        self::assertSame([20, 30], array_column($rows, 'category_id'));
        self::assertSame(1, $rows[0]['subcategories']);
        self::assertSame(0, $rows[1]['subcategories']);
    }

    #[Test]
    public function thePathNamesTheTreeBelowTheStoreRoot(): void
    {
        $rows = EmptyCategoriesReport::emptyCategories(
            [
                10 => $this->node('1/2/10', 'Men', anchor: false),
                11 => $this->node('1/2/10/11', 'Shoes', anchor: false),
                12 => $this->node('1/2/10/11/12', 'Sale'),
            ],
            [10 => 1, 11 => 1],
            [10 => 1, 11 => 1],
            [],
            self::ROOT
        );

        self::assertSame('Men > Shoes > Sale', $rows[0]['path']);
    }

    /**
     * Status and visibility take the store view value over the default, the website filter applies
     * and only catalog-visible products count; the tree is read from below the store's root.
     */
    #[Test]
    public function liveProductsAreCountedInTheStoreViewAndWebsite(): void
    {
        $this->report()->forStore(3, 2, self::ROOT);

        [$categories, $overrides, $live, $assigned] = $this->queries;
        self::assertStringContainsString('store_id = 3', $overrides);
        self::assertStringContainsString('status_s.store_id = 3', $live);
        self::assertStringContainsString("cc.path LIKE '1/2/%'", $categories);
        self::assertStringContainsString('is_active_s.store_id = 3', $categories);
        self::assertStringContainsString('cpw.website_id = 2', $live);
        self::assertStringContainsString("IFNULL(status_s.value, status_d.value) = 1", $live);
        self::assertStringContainsString('IFNULL(visibility_s.value, visibility_d.value) IN (2, 4)', $live);
        self::assertStringContainsString('COUNT(DISTINCT ccp.product_id)', $live);
        self::assertStringNotContainsString('status', $assigned);
    }

    #[Test]
    public function stockIsLeftOutWhileOutOfStockProductsAreShown(): void
    {
        $this->report(hidesOutOfStock: false)->forStore(3, 2, self::ROOT);

        $live = $this->queriesContaining('AS `live`')[0];
        self::assertStringNotContainsString('stock', $live);
    }

    #[Test]
    public function hiddenOutOfStockProductsAreCountedApartFromTheLegacyStockStatus(): void
    {
        $this->liveRows = [['category_id' => 10, 'live' => 2, 'shown' => 0]];
        $result = $this->report(hidesOutOfStock: true)->forStore(3, 2, self::ROOT);

        $live = $this->queriesContaining('AS `live`')[0];
        self::assertStringContainsString(
            'LEFT JOIN `cataloginventory_stock_status` AS `stock` ON stock.product_id = cpe.entity_id '
            . 'AND stock.website_id = 0 AND stock.stock_id = 1',
            $live
        );
        self::assertStringContainsString(
            'COUNT(DISTINCT CASE WHEN stock.stock_status = 1 THEN ccp.product_id END) AS `shown`',
            $live
        );
        self::assertSame(EmptyCategoriesReport::REASON_OUT_OF_STOCK, $result['empty'][0]['reason']);
    }

    /**
     * @return list<string>
     */
    private function queriesContaining(string $needle): array
    {
        return array_values(array_filter(
            $this->queries,
            static fn (string $sql): bool => str_contains($sql, $needle)
        ));
    }

    /**
     * @return array{path:string,level:int,name:string,is_active:bool,is_anchor:bool,display_mode:string}
     */
    private function node(string $path, string $name, bool $active = true, bool $anchor = true): array
    {
        return [
            'path' => $path,
            'level' => substr_count($path, '/'),
            'name' => $name,
            'is_active' => $active,
            'is_anchor' => $anchor,
            'display_mode' => 'PRODUCTS',
        ];
    }

    private function report(bool $hidesOutOfStock = false): EmptyCategoriesReport
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
        $adapter->method('getIfNullSql')->willReturnCallback(
            static fn (string $expression, string $value): \Zend_Db_Expr => new \Zend_Db_Expr(
                sprintf('IFNULL(%s, %s)', $expression, $value)
            )
        );
        $adapter->method('fetchAll')->willReturnCallback(function (Select $select): array {
            $this->queries[] = (string)$select;
            if (str_contains((string)$select, 'AS `live`')) {
                return $this->liveRows;
            }
            return [['entity_id' => 10, 'path' => '1/2/10', 'level' => 2, 'name' => 'Sale',
                'is_active' => 1, 'is_anchor' => 1, 'display_mode' => 'PRODUCTS']];
        });
        $adapter->method('fetchOne')->willReturnCallback(function (Select $select): string|false {
            $this->queries[] = (string)$select;
            return $this->storeOverrides ? '1' : false;
        });
        $adapter->method('limit')->willReturnCallback(
            static fn (string $sql, int $count): string => $sql . ' LIMIT ' . $count
        );
        $adapter->method('fetchPairs')->willReturnCallback(function (Select $select): array {
            $this->queries[] = (string)$select;
            return [];
        });

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($adapter);
        $resource->method('getTableName')->willReturnArgument(0);

        $ids = [
            'name' => 45,
            'is_active' => 46,
            'is_anchor' => 54,
            'display_mode' => 52,
            'status' => 97,
            'visibility' => 99,
        ];
        $eavConfig = $this->createStub(EavConfig::class);
        $eavConfig->method('getAttribute')->willReturnCallback(function (string $entity, string $code) use ($ids) {
            $attribute = $this->createStub(AbstractAttribute::class);
            $attribute->method('getAttributeId')->willReturn($ids[$code]);
            $attribute->method('getBackendTable')->willReturn(
                in_array($code, ['name', 'display_mode'], true) ? $entity . '_entity_varchar' : $entity . '_entity_int'
            );
            return $attribute;
        });

        $metadata = $this->createStub(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn('entity_id');
        $metadataPool = $this->createStub(MetadataPool::class);
        $metadataPool->method('getMetadata')->willReturn($metadata);

        $stockConfiguration = $this->createStub(StockConfigurationInterface::class);
        $stockConfiguration->method('isShowOutOfStock')->willReturn(!$hidesOutOfStock);
        $stockConfiguration->method('getDefaultScopeId')->willReturn(0);
        $modules = $this->createStub(ModuleManager::class);
        $modules->method('isEnabled')->willReturn(false);
        $salableProducts = new SalableProducts(
            $resource,
            $stockConfiguration,
            new MsiAvailability($modules),
            $this->createStub(StoreManagerInterface::class),
            $this->createStub(ObjectManagerInterface::class)
        );

        return new EmptyCategoriesReport($resource, $eavConfig, $metadataPool, new Visibility(
            $this->createStub(\Magento\Eav\Model\ResourceModel\Entity\Attribute::class)
        ), $salableProducts);
    }

    private function selectRenderer(): SelectRenderer
    {
        $quote = new Quote();
        $parts = [
            ['columns', new ColumnsRenderer($quote)],
            ['from', new FromRenderer($quote)],
            ['where', new WhereRenderer()],
            ['group', new GroupRenderer($quote)],
            ['order', new OrderRenderer($quote)],
        ];
        $renderers = [];
        foreach ($parts as $sort => [$part, $renderer]) {
            $renderers[] = ['renderer' => $renderer, 'sort' => ($sort + 1) * 100, 'part' => $part];
        }

        return new SelectRenderer($renderers);
    }
}

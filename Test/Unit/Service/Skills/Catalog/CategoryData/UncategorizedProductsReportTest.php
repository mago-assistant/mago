<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Catalog\CategoryData;

use Magento\Catalog\Model\Product\Visibility;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Platform\Quote;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Select\ColumnsRenderer;
use Magento\Framework\DB\Select\FromRenderer;
use Magento\Framework\DB\Select\LimitRenderer;
use Magento\Framework\DB\Select\OrderRenderer;
use Magento\Framework\DB\Select\SelectRenderer;
use Magento\Framework\DB\Select\WhereRenderer;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use MagoAssistant\Mago\Service\Skills\Catalog\CategoryData\CategoryTree;
use MagoAssistant\Mago\Service\Skills\Catalog\CategoryData\ScopedAttributeJoin;
use MagoAssistant\Mago\Service\Skills\Catalog\CategoryData\UncategorizedProductsReport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The database adapter is stubbed, but the selects are rendered by Magento's own renderers, so the
 * tests read the SQL the report would send.
 */
final class UncategorizedProductsReportTest extends TestCase
{
    /** @var list<string> */
    private array $queries = [];

    /** @var array<int|string,string> */
    private array $fetchPairs = [];

    /** @var list<array<string,mixed>> */
    private array $fetchAll = [];

    /** @var int What the count query returns */
    private int $count = 0;

    /** @var bool Whether the store view has its own product status or visibility values */
    private bool $storeOverrides = true;

    /**
     * A product counts when no enabled category below the root links to it; the reason tells why.
     */
    #[Test]
    public function productsWithoutAnEnabledCategoryBelowTheRootAreFoundWithTheirReason(): void
    {
        $this->fetchPairs = ['7' => UncategorizedProductsReport::REASON_ROOT_ONLY];
        $tree = [
            10 => $this->node('1/2/10', true),
            11 => $this->node('1/2/11', false),
        ];

        $this->count = 4;
        $result = $this->report($tree)->forStore(3, 2, 2, false, 50);

        self::assertSame(
            ['count' => 4, 'reasons' => [7 => UncategorizedProductsReport::REASON_ROOT_ONLY]],
            $result
        );
        $sql = $this->scanQueries()[0];
        self::assertStringContainsString(
            "CASE WHEN NOT EXISTS (SELECT 1 FROM `catalog_category_product` AS `ccp` "
            . "WHERE (ccp.product_id = cpe.entity_id)) THEN 'no_category'",
            $sql
        );
        self::assertStringContainsString("(ccp.category_id IN (10, 11))) THEN 'root_only'", $sql);
        self::assertStringContainsString("ELSE 'disabled_categories_only' END", $sql);
        self::assertStringContainsString('WHERE (NOT EXISTS (SELECT 1', $sql);
        self::assertStringContainsString('(ccp.category_id IN (10)))', $sql);
        self::assertStringNotContainsString('catalog_product_website', $sql);
        self::assertStringContainsString('ORDER BY `cpe`.`sku` ASC', $sql);
        self::assertStringEndsWith('LIMIT 50', $sql);
    }

    /**
     * The count runs the same conditions without loading the products, and only the listed products
     * are fetched: a large catalog never ends up in memory.
     */
    #[Test]
    public function theCountRunsWithoutLoadingTheProducts(): void
    {
        $this->report([10 => $this->node('1/2/10', true)])->forStore(3, 2, 2, false, 5);

        $count = array_values(array_filter(
            $this->queries,
            static fn (string $sql): bool => str_contains($sql, 'AS `count`')
        ));
        self::assertCount(1, $count);
        self::assertStringStartsWith('SELECT COUNT(*) AS `count` FROM `catalog_product_entity` AS `cpe`', $count[0]);
        self::assertStringContainsString('WHERE (NOT EXISTS (SELECT 1', $count[0]);
        self::assertStringNotContainsString('ORDER BY', $count[0]);
        self::assertStringEndsWith('LIMIT 5', $this->scanQueries()[0]);
    }

    #[Test]
    public function onlyLiveProductsAreEnabledVisibleInTheCatalogAndInTheWebsite(): void
    {
        $this->report([10 => $this->node('1/2/10', true)])->forStore(3, 2, 2, true, 50);

        $sql = $this->scanQueries()[0];
        self::assertStringContainsString('cpw.product_id = cpe.entity_id AND cpw.website_id = 2', $sql);
        self::assertStringContainsString('status_s.store_id = 3', $sql);
        self::assertStringContainsString('(IFNULL(status_s.value, status_d.value) = 1)', $sql);
        self::assertStringContainsString('(IFNULL(visibility_s.value, visibility_d.value) IN (2, 4))', $sql);
    }

    /**
     * Without enabled categories below the root every product counts: the condition must not become
     * an empty IN () list.
     */
    #[Test]
    public function aStoreWithoutEnabledCategoriesCountsEveryProduct(): void
    {
        $this->report([10 => $this->node('1/2/10', false)])->forStore(1, 1, 2, false, 50);

        self::assertStringContainsString('WHERE (NOT (1 = 0))', $this->scanQueries()[0]);
        self::assertStringNotContainsString('IN ()', $this->scanQueries()[0]);
    }

    /**
     * Store views without their own status and visibility values, in one website and with the same
     * enabled categories, show the same products: they share one scan, read on the default scope.
     */
    #[Test]
    public function storeViewsShowingTheSameProductsShareOneScan(): void
    {
        $this->storeOverrides = false;
        $report = $this->report([10 => $this->node('1/2/10', true)]);
        $report->forStore(1, 1, 2, true, 50);
        $report->forStore(3, 1, 2, true, 50);
        $report->forStore(4, 2, 2, true, 50);

        $scans = $this->scanQueries();
        self::assertCount(2, $scans);
        self::assertStringContainsString('status_s.store_id = 0', $scans[0]);
        self::assertStringContainsString('cpw.website_id = 2', $scans[1]);
    }

    #[Test]
    public function aStoreViewWithItsOwnValuesGetsItsOwnScan(): void
    {
        $report = $this->report([10 => $this->node('1/2/10', true)]);
        $report->forStore(1, 1, 2, true, 50);
        $report->forStore(3, 1, 2, true, 50);

        $scans = $this->scanQueries();
        self::assertCount(2, $scans);
        self::assertStringContainsString('status_s.store_id = 3', $scans[1]);
    }

    #[Test]
    public function detailsGiveTheStoreViewValuesOfTheListedProducts(): void
    {
        $this->fetchAll = [
            [
                'entity_id' => 7,
                'sku' => 'A-1',
                'type_id' => 'simple',
                'name' => 'Helmet',
                'status' => 2,
                'visibility' => 4,
            ],
        ];

        $details = $this->report([])->details([7], 3);

        self::assertSame(
            [7 => ['sku' => 'A-1', 'name' => 'Helmet', 'type' => 'simple', 'status' => 'disabled',
                'visibility' => 'Catalog, Search']],
            $details
        );
        self::assertStringContainsString('cpe.entity_id IN (7)', $this->queries[0]);
        self::assertStringContainsString('name_s.store_id = 3', $this->queries[0]);
    }

    #[Test]
    public function noProductsNeedNoDetailsQuery(): void
    {
        self::assertSame([], $this->report([])->details([], 1));
        self::assertSame([], $this->queries);
    }

    /**
     * @return list<string> The product scans, without the store value probes
     */
    private function scanQueries(): array
    {
        return array_values(array_filter(
            $this->queries,
            static fn (string $sql): bool => str_contains($sql, 'AS `reason`')
        ));
    }

    /**
     * @return array{path:string,level:int,name:string,is_active:bool,is_anchor:bool,display_mode:string}
     */
    private function node(string $path, bool $active): array
    {
        return [
            'path' => $path,
            'level' => substr_count($path, '/'),
            'name' => 'Category',
            'is_active' => $active,
            'is_anchor' => true,
            'display_mode' => 'PRODUCTS',
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $tree
     */
    private function report(array $tree): UncategorizedProductsReport
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
        $adapter->method('fetchPairs')->willReturnCallback(function (Select $select): array {
            $this->queries[] = (string)$select;
            return $this->fetchPairs;
        });
        $adapter->method('fetchOne')->willReturnCallback(function (Select $select): string|false {
            $this->queries[] = (string)$select;
            if (str_contains((string)$select, 'AS `count`')) {
                return (string)$this->count;
            }
            return $this->storeOverrides ? '1' : false;
        });
        $adapter->method('limit')->willReturnCallback(
            static fn (string $sql, int $count): string => $sql . ' LIMIT ' . $count
        );
        $adapter->method('fetchAll')->willReturnCallback(function (Select $select): array {
            $this->queries[] = (string)$select;
            return $this->fetchAll;
        });

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($adapter);
        $resource->method('getTableName')->willReturnArgument(0);

        $ids = ['name' => 73, 'status' => 97, 'visibility' => 99];
        $eavConfig = $this->createStub(EavConfig::class);
        $eavConfig->method('getAttribute')->willReturnCallback(function (string $entity, string $code) use ($ids) {
            $attribute = $this->createStub(AbstractAttribute::class);
            $attribute->method('getAttributeId')->willReturn($ids[$code]);
            $attribute->method('getBackendTable')->willReturn(
                $code === 'name' ? 'catalog_product_entity_varchar' : 'catalog_product_entity_int'
            );
            return $attribute;
        });

        $metadata = $this->createStub(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn('entity_id');
        $metadataPool = $this->createStub(MetadataPool::class);
        $metadataPool->method('getMetadata')->willReturn($metadata);

        $categoryTree = $this->createStub(CategoryTree::class);
        $categoryTree->method('below')->willReturn($tree);

        return new UncategorizedProductsReport(
            $resource,
            $metadataPool,
            new Visibility($this->createStub(\Magento\Eav\Model\ResourceModel\Entity\Attribute::class)),
            $categoryTree,
            new ScopedAttributeJoin($resource, $eavConfig)
        );
    }

    private function selectRenderer(): SelectRenderer
    {
        $quote = new Quote();
        $parts = [
            ['columns', new ColumnsRenderer($quote)],
            ['from', new FromRenderer($quote)],
            ['where', new WhereRenderer()],
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

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Analytics\ProductData;

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
use MagoAssistant\Mago\Service\Skills\Analytics\ProductData\AttributeFillRateAttributes;
use MagoAssistant\Mago\Service\Skills\Analytics\ProductData\AttributeFillRateReport;
use MagoAssistant\Mago\Service\Skills\Analytics\ProductData\AttributeFillRateScope;
use MagoAssistant\Mago\Service\Skills\Catalog\CategoryData\ScopedAttributeJoin;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #319. The database adapter is stubbed, but the selects are rendered by Magento's own
 * renderers, so the tests read the SQL the report would send.
 *
 * The catalog: material (text, store view scope) is in sets 4 and 15; price_type (int, global) is in
 * set 4 and applies to bundles only; format is in no set. Set 4 holds 10 simple products and 1 bundle,
 * set 15 holds 5 simple products.
 */
final class AttributeFillRateReportTest extends TestCase
{
    private const MATERIAL = 101;
    private const PRICE_TYPE = 102;
    private const FORMAT = 103;

    /** @var list<string> */
    private array $queries = [];

    #[Test]
    public function onlyProductsWhoseSetAndTypeCanHaveTheAttributeCount(): void
    {
        $report = $this->report()->build(new AttributeFillRateScope());

        self::assertSame(16, $report['product_count']);
        self::assertSame(1, $report['attributes_without_products']);
        self::assertSame(
            [
                'material' => [5, 16, 11, 31.3],
                'price_type' => [1, 1, 0, 100.0],
            ],
            $this->summary($report['attributes'])
        );
    }

    #[Test]
    public function blankStringsAndNoImageAreEmptyButZeroIsAValue(): void
    {
        $this->report()->build(new AttributeFillRateScope());

        $text = $this->query('FROM `catalog_product_entity_text` AS `v`');
        self::assertStringContainsString('(v.value IS NOT NULL)', $text);
        self::assertStringContainsString("(TRIM(v.value) <> '')", $text);
        self::assertStringContainsString("(v.value <> 'no_selection')", $text);
        $int = $this->query('FROM `catalog_product_entity_int` AS `v`');
        self::assertStringContainsString('(v.value IS NOT NULL)', $int);
        self::assertStringNotContainsString('TRIM', $int);
    }

    /**
     * One grouped select per value table that has attributes in a set, never one per attribute or product.
     */
    #[Test]
    public function theDefaultScopeReadsTheDefaultValuesOfEveryProduct(): void
    {
        $this->report()->build(new AttributeFillRateScope());

        $values = $this->valueQueries();
        self::assertCount(2, $values);
        foreach ($values as $sql) {
            self::assertStringContainsString('(v.store_id = 0)', $sql);
            self::assertStringContainsString('GROUP BY `v`.`attribute_id`, `cpe`.`attribute_set_id`, `cpe`.`type_id`', $sql);
            self::assertStringNotContainsString('catalog_product_website', $sql);
            self::assertStringNotContainsString('AS `s`', $sql);
        }
    }

    /**
     * A store view value replaces the default one; a NULL store value falls back to it. Global
     * attributes have no store view value, so a stale store row of theirs is never read.
     */
    #[Test]
    public function aStoreViewReadsItsOwnValuesWithTheDefaultAsFallback(): void
    {
        $this->report()->build(new AttributeFillRateScope(3, 2));

        $default = $this->query('FROM `catalog_product_entity_text` AS `v`', '(v.store_id = 0)');
        self::assertStringContainsString(
            'LEFT JOIN `catalog_product_entity_text` AS `s` ON s.entity_id = v.entity_id AND s.attribute_id = v.attribute_id '
            . 'AND s.store_id = 3 AND v.attribute_id IN (101)',
            $default
        );
        self::assertStringContainsString('(s.value IS NULL)', $default);
        $store = $this->query('FROM `catalog_product_entity_text` AS `v`', '(v.store_id = 3)');
        self::assertStringContainsString('(v.attribute_id IN (101))', $store);
        self::assertStringNotContainsString('AS `s`', $this->query('FROM `catalog_product_entity_int` AS `v`'));
        self::assertSame([], array_filter(
            $this->valueQueries(),
            static fn (string $sql): bool => str_contains($sql, 'catalog_product_entity_int` AS `v`') && str_contains($sql, 'store_id = 3)')
        ));
    }

    #[Test]
    public function aStoreViewOnlyCountsTheProductsInItsWebsite(): void
    {
        $this->report()->build(new AttributeFillRateScope(3, 2));

        $counted = [$this->query('FROM `catalog_product_entity` AS `cpe`'), ...$this->valueQueries()];
        foreach ($counted as $sql) {
            self::assertStringContainsString('cpw.product_id = cpe.entity_id AND cpw.website_id = 2', $sql);
        }
    }

    /**
     * Store values and default values the store view does not override are different products, so
     * their counts add up.
     */
    #[Test]
    public function storeValuesAndDefaultValuesAddUp(): void
    {
        $report = $this->report(['catalog_product_entity_text/3' => [[self::MATERIAL, 4, 'simple', 2]]])
            ->build(new AttributeFillRateScope(3, 2));

        self::assertSame([7, 16, 9, 43.8], $this->summary($report['attributes'])['material']);
    }

    #[Test]
    public function anAttributeSetLimitsTheProductsAndTheAttributes(): void
    {
        $report = $this->report()->build(new AttributeFillRateScope(attributeSetId: 15));

        self::assertSame(['material' => [2, 5, 3, 40.0]], $this->summary($report['attributes']));
        self::assertSame(0, $report['attributes_without_products']);
        self::assertStringContainsString('(cpe.attribute_set_id = 15)', $this->query('FROM `catalog_product_entity` AS `cpe`'));
        self::assertStringContainsString('(cpe.attribute_set_id = 15)', $this->valueQueries()[0]);
    }

    #[Test]
    public function onlyEnabledReadsTheStatusOfTheScope(): void
    {
        $this->report()->build(new AttributeFillRateScope(3, 2, null, true));

        $products = $this->query('FROM `catalog_product_entity` AS `cpe`');
        self::assertStringContainsString('status_s.store_id = 3', $products);
        self::assertStringContainsString('(IFNULL(status_s.value, status_d.value) = 1)', $products);
        self::assertStringContainsString('(IFNULL(status_s.value, status_d.value) = 1)', $this->valueQueries()[0]);
    }

    #[Test]
    public function onlyTheMerchantsOwnAttributesUnlessSystemOnesAreAsked(): void
    {
        $this->report()->build(new AttributeFillRateScope());
        $this->report()->build(new AttributeFillRateScope(isIncludingSystem: true));

        $attributeQueries = array_values(array_filter($this->queries, static fn (string $sql): bool => str_contains($sql, 'AS `ea`')));
        self::assertStringContainsString('(ea.is_user_defined = 1)', $attributeQueries[0]);
        self::assertStringNotContainsString('is_user_defined = 1', $attributeQueries[1]);
    }

    #[Test]
    public function attributesWithoutPerProductValuesAreLeftOut(): void
    {
        $this->report()->build(new AttributeFillRateScope());

        $sql = $this->query('AS `ea`');
        self::assertStringContainsString("(ea.backend_type IN ('varchar', 'int', 'decimal', 'text', 'datetime'))", $sql);
        self::assertStringContainsString("(ea.attribute_code NOT IN ('tier_price', 'quantity_and_stock_status', 'gallery'))", $sql);
        self::assertStringContainsString("(et.entity_type_code = 'catalog_product')", $sql);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,array{int,int,int,float}>
     */
    private function summary(array $rows): array
    {
        $summary = [];
        foreach ($rows as $row) {
            $summary[$row['attribute_code']] = [$row['filled_count'], $row['applicable_count'], $row['empty_count'], $row['fill_rate']];
        }

        return $summary;
    }

    private function query(string ...$needles): string
    {
        foreach ($this->queries as $sql) {
            if (array_filter($needles, static fn (string $needle): bool => !str_contains($sql, $needle)) === []) {
                return $sql;
            }
        }
        self::fail('No query contains ' . implode(' and ', $needles));
    }

    /**
     * @return list<string>
     */
    private function valueQueries(): array
    {
        return array_values(array_filter(
            $this->queries,
            static fn (string $sql): bool => preg_match('/FROM `catalog_product_entity_\w+` AS `v`/', $sql) === 1
        ));
    }

    /**
     * Rows per value table and store id; a stale price_type value on a simple product shows apply_to at work.
     *
     * @return array<string,list<array{int,int,string,int}>> attribute id, set, type, count
     */
    private function defaultValues(): array
    {
        return [
            'catalog_product_entity_text/0' => [[self::MATERIAL, 4, 'simple', 3], [self::MATERIAL, 15, 'simple', 2]],
            'catalog_product_entity_int/0' => [[self::PRICE_TYPE, 4, 'bundle', 1], [self::PRICE_TYPE, 4, 'simple', 4]],
            'catalog_product_entity_varchar/0' => [],
        ];
    }

    /**
     * @param array<string,list<array{int,int,string,int}>> $storeValues
     */
    private function report(array $storeValues = []): AttributeFillRateReport
    {
        $values = $storeValues + $this->defaultValues();
        $adapter = $this->createStub(Mysql::class);
        $renderer = $this->selectRenderer();
        $adapter->method('select')->willReturnCallback(static fn (): Select => new Select($adapter, $renderer));
        $quote = static fn (mixed $value): string => is_int($value) ? (string)$value : "'" . $value . "'";
        $adapter->method('quote')->willReturnCallback(
            static fn (mixed $value): string => is_array($value) ? implode(', ', array_map($quote, $value)) : $quote($value)
        );
        $adapter->method('quoteInto')->willReturnCallback(
            static fn (string $text, mixed $value): string => str_replace('?', $adapter->quote($value), $text)
        );
        $adapter->method('getIfNullSql')->willReturnCallback(
            static fn (string $expression, string $value): \Zend_Db_Expr => new \Zend_Db_Expr(sprintf('IFNULL(%s, %s)', $expression, $value))
        );
        $adapter->method('fetchAll')->willReturnCallback(function (Select $select) use ($values): array {
            $sql = (string)preg_replace('/\s+/', ' ', (string)$select);
            $this->queries[] = $sql;
            return $this->rowsFor($sql, $values);
        });
        $adapter->method('fetchPairs')->willReturn([4 => 'Default', 15 => 'Bag']);

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($adapter);
        $resource->method('getTableName')->willReturnArgument(0);

        $eavConfig = $this->createStub(EavConfig::class);
        $eavConfig->method('getAttribute')->willReturnCallback(function () {
            $attribute = $this->createStub(AbstractAttribute::class);
            $attribute->method('getAttributeId')->willReturn(97);
            $attribute->method('getBackendTable')->willReturn('catalog_product_entity_int');
            return $attribute;
        });

        $metadata = $this->createStub(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn('entity_id');
        $metadataPool = $this->createStub(MetadataPool::class);
        $metadataPool->method('getMetadata')->willReturn($metadata);

        return new AttributeFillRateReport(
            $resource,
            $metadataPool,
            new ScopedAttributeJoin($resource, $eavConfig),
            new AttributeFillRateAttributes($resource)
        );
    }

    /**
     * @param array<string,list<array{int,int,string,int}>> $values
     * @return list<array<string,mixed>>
     */
    private function rowsFor(string $sql, array $values): array
    {
        if (str_contains($sql, 'AS `ea`')) {
            return [
                $this->attribute(self::MATERIAL, 'material', 'text', 0, ''),
                $this->attribute(self::PRICE_TYPE, 'price_type', 'int', 1, 'bundle'),
                $this->attribute(self::FORMAT, 'format', 'varchar', 0, null),
            ];
        }
        if (str_contains($sql, 'AS `eea`')) {
            return [
                ['attribute_set_id' => 4, 'attribute_id' => self::MATERIAL],
                ['attribute_set_id' => 4, 'attribute_id' => self::PRICE_TYPE],
                ['attribute_set_id' => 15, 'attribute_id' => self::MATERIAL],
            ];
        }
        if (preg_match('/FROM `(catalog_product_entity_\w+)` AS `v`.*v\.store_id = (\d+)/s', $sql, $match) === 1) {
            return array_map(
                static fn (array $row): array => array_combine(['attribute_id', 'attribute_set_id', 'type_id', 'count'], $row),
                $values[$match[1] . '/' . $match[2]] ?? []
            );
        }

        return [
            ['attribute_set_id' => 4, 'type_id' => 'simple', 'count' => 10],
            ['attribute_set_id' => 4, 'type_id' => 'bundle', 'count' => 1],
            ['attribute_set_id' => 15, 'type_id' => 'simple', 'count' => 5],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function attribute(int $id, string $code, string $backendType, int $isGlobal, ?string $applyTo): array
    {
        return [
            'attribute_id' => $id,
            'attribute_code' => $code,
            'frontend_label' => ucfirst($code),
            'frontend_input' => 'text',
            'backend_type' => $backendType,
            'is_required' => 0,
            'is_user_defined' => 1,
            'is_global' => $isGlobal,
            'apply_to' => $applyTo,
        ];
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

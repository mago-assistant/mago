<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\LayeredNavigation;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Indexer\Category\Product\TableMaintainer;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Product attributes as layered navigation sees them: which ones are filters, their options, and
 * which values the products carry
 *
 * Reads the EAV tables directly so an audit of a large category stays a handful of queries. A
 * store view's own value wins over the default scope, as on the storefront.
 */
class FilterAttributes
{
    public const PRODUCT_ENTITY_TYPE = 'catalog_product';

    /** Inputs layered navigation can filter on, the ones the attribute form allows */
    public const FILTERABLE_INPUTS = ['select', 'multiselect', 'boolean', 'price', 'swatch_visual', 'swatch_text'];

    /** Inputs whose values are option ids */
    public const OPTION_INPUTS = ['select', 'multiselect', 'swatch_visual', 'swatch_text'];

    /** Storefront visibility "Catalog" and "Catalog, Search" */
    private const VISIBLE_IN_CATALOG = [2, 4];

    private ?string $linkField = null;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly MetadataPool $metadataPool,
        private readonly TableMaintainer $categoryIndexTables,
        private readonly CategoryCollectionFactory $categoryCollectionFactory
    ) {
    }

    /**
     * Product attributes, filters first, each with how it is set up for layered navigation
     *
     * @param bool $onlyFilters Only attributes shown as a filter on category or search pages
     * @return array<int, array{attribute_id: int, code: string, label: string, input: string,
     *     backend_type: string, is_filterable: int, is_filterable_in_search: bool, position: int,
     *     is_user_defined: bool}> Keyed by attribute id
     */
    public function attributes(bool $onlyFilters): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['a' => $this->resource->getTableName('eav_attribute')], [
                'attribute_id',
                'code' => 'attribute_code',
                'label' => 'frontend_label',
                'frontend_input',
                'backend_type',
                'is_user_defined',
            ])
            ->join(
                ['c' => $this->resource->getTableName('catalog_eav_attribute')],
                'c.attribute_id = a.attribute_id',
                ['is_filterable', 'is_filterable_in_search', 'position', 'additional_data']
            )
            ->join(
                ['t' => $this->resource->getTableName('eav_entity_type')],
                't.entity_type_id = a.entity_type_id',
                []
            )
            ->where('t.entity_type_code = ?', self::PRODUCT_ENTITY_TYPE)
            ->order(['c.position ASC', 'a.frontend_label ASC']);
        if ($onlyFilters) {
            $select->where('c.is_filterable > 0 OR c.is_filterable_in_search = 1');
        }

        $attributes = [];
        foreach ($connection->fetchAll($select) as $row) {
            $attributes[(int)$row['attribute_id']] = [
                'attribute_id' => (int)$row['attribute_id'],
                'code' => (string)$row['code'],
                'label' => (string)($row['label'] ?: $row['code']),
                'input' => $this->input($row),
                'backend_type' => (string)$row['backend_type'],
                'is_filterable' => (int)$row['is_filterable'],
                'is_filterable_in_search' => (bool)$row['is_filterable_in_search'],
                'position' => (int)$row['position'],
                'is_user_defined' => (bool)$row['is_user_defined'],
            ];
        }

        return $attributes;
    }

    /**
     * One product attribute by code, null when there is none
     *
     * @param string $code
     * @return array|null Same shape as attributes()
     */
    public function attribute(string $code): ?array
    {
        foreach ($this->attributes(false) as $attribute) {
            if (strcasecmp($attribute['code'], $code) === 0) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * Attribute set names per attribute, and how many product attribute sets there are
     *
     * @param int[] $attributeIds
     * @return array{0: array<int, string[]>, 1: int} Attribute id => set names; total set count
     */
    public function attributeSets(array $attributeIds): array
    {
        $connection = $this->resource->getConnection();
        $sets = $connection->fetchPairs(
            $connection->select()
                ->from(
                    ['s' => $this->resource->getTableName('eav_attribute_set')],
                    ['attribute_set_id', 'name' => 'attribute_set_name']
                )
                ->join(
                    ['t' => $this->resource->getTableName('eav_entity_type')],
                    't.entity_type_id = s.entity_type_id',
                    []
                )
                ->where('t.entity_type_code = ?', self::PRODUCT_ENTITY_TYPE)
        );
        if ($attributeIds === []) {
            return [[], count($sets)];
        }

        $byAttribute = [];
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName('eav_entity_attribute'), ['attribute_id', 'attribute_set_id'])
                ->where('attribute_id IN (?)', $attributeIds)
                ->where('attribute_set_id IN (?)', array_keys($sets) ?: [0])
        );
        foreach ($rows as $row) {
            $byAttribute[(int)$row['attribute_id']][] = (string)$sets[$row['attribute_set_id']];
        }

        return [array_map(static fn (array $names) => array_values(array_unique($names)), $byAttribute), count($sets)];
    }

    /**
     * An attribute's options with their label in the store view, the admin label as fallback
     *
     * Yes/No attributes have no option rows; they get 1 and 0.
     *
     * @param array $attribute From attributes()
     * @param int $storeId
     * @return array<int, string> Option id => label, in sort order
     */
    public function options(array $attribute, int $storeId = 0): array
    {
        if ($attribute['input'] === 'boolean') {
            return [1 => 'Yes', 0 => 'No'];
        }
        if (!in_array($attribute['input'], self::OPTION_INPUTS, true)) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $valueTable = $this->resource->getTableName('eav_attribute_option_value');
        $select = $connection->select()
            ->from(['o' => $this->resource->getTableName('eav_attribute_option')], ['option_id'])
            ->joinLeft(['d' => $valueTable], 'd.option_id = o.option_id AND d.store_id = 0', [])
            ->joinLeft(
                ['s' => $valueTable],
                $connection->quoteInto('s.option_id = o.option_id AND s.store_id = ?', $storeId),
                ['label' => new \Zend_Db_Expr('COALESCE(s.value, d.value)')]
            )
            ->where('o.attribute_id = ?', $attribute['attribute_id'])
            ->order(['o.sort_order ASC', 'o.option_id ASC']);

        return array_map('strval', $connection->fetchPairs($select));
    }

    /**
     * Categories below the store roots with this id, or with exactly this name in the store view
     *
     * @param string $reference Category id or name
     * @param int $storeId
     * @return list<array{id: int, name: string}>
     */
    public function findCategories(string $reference, int $storeId): array
    {
        $collection = $this->categoryCollectionFactory->create()
            ->setStoreId($storeId)
            ->addAttributeToSelect('name')
            ->addAttributeToFilter('level', ['gt' => 1]);
        if (ctype_digit($reference)) {
            $collection->addAttributeToFilter('entity_id', (int)$reference);
        } else {
            $collection->addAttributeToFilter('name', $reference);
        }

        return array_values(array_map(
            static fn ($category) => ['id' => (int)$category->getId(), 'name' => (string)$category->getName()],
            $collection->getItems()
        ));
    }

    /**
     * Products a shopper finds in a category in a store view: enabled, in the website, visible in the
     * catalog, as the category product index holds them (anchor categories include their children)
     *
     * @param int $categoryId
     * @param int $storeId
     * @return int[] Product entity ids
     */
    public function categoryProducts(int $categoryId, int $storeId): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->categoryIndexTables->getMainTable($storeId);
        if (!$connection->isTableExists($table)) {
            return [];
        }

        return array_map('intval', $connection->fetchCol(
            $connection->select()
                ->distinct()
                ->from($table, ['product_id'])
                ->where('category_id = ?', $categoryId)
                ->where('visibility IN (?)', self::VISIBLE_IN_CATALOG)
        ));
    }

    /**
     * Variants of the given products (configurable, grouped, bundle children)
     *
     * @param int[] $productIds Parent entity ids
     * @return array<int, int[]> Parent entity id => child entity ids
     */
    public function children(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(['r' => $this->resource->getTableName('catalog_product_relation')], ['child_id'])
                ->join(
                    ['e' => $this->resource->getTableName('catalog_product_entity')],
                    'e.' . $this->linkField() . ' = r.parent_id',
                    ['parent_id' => 'entity_id']
                )
                ->where('e.entity_id IN (?)', $productIds)
        );

        $children = [];
        foreach ($rows as $row) {
            $children[(int)$row['parent_id']][] = (int)$row['child_id'];
        }

        return $children;
    }

    /**
     * The values the products carry for the attributes, the store view's own value over the default
     *
     * @param array[] $attributes From attributes(); any backend type
     * @param int[] $productIds Entity ids
     * @param int $storeId
     * @return array<int, array<int, string[]>> Attribute id => product entity id => values (option ids
     *     split for a multiselect); products without a value are left out
     */
    public function values(array $attributes, array $productIds, int $storeId): array
    {
        $byTable = [];
        foreach ($attributes as $attribute) {
            if ($attribute['backend_type'] !== 'static') {
                $byTable[$attribute['backend_type']][$attribute['attribute_id']] = $attribute;
            }
        }
        if ($productIds === [] || $byTable === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $values = [];
        foreach ($byTable as $type => $tableAttributes) {
            $rows = $connection->fetchAll(
                $connection->select()
                    ->from(
                        ['v' => $this->resource->getTableName('catalog_product_entity_' . $type)],
                        ['attribute_id', 'store_id', 'value']
                    )
                    ->join(
                        ['e' => $this->resource->getTableName('catalog_product_entity')],
                        'e.' . $this->linkField() . ' = v.' . $this->linkField(),
                        ['entity_id']
                    )
                    ->where('v.attribute_id IN (?)', array_keys($tableAttributes))
                    ->where('v.store_id IN (?)', array_unique([0, $storeId]))
                    ->where('e.entity_id IN (?)', $productIds)
                    ->order('v.store_id ASC')
            );
            // Default scope first, so a store view row overwrites it.
            foreach ($rows as $row) {
                $values[(int)$row['attribute_id']][(int)$row['entity_id']] = $row['value'];
            }
        }

        $attributesById = array_replace(...array_values($byTable));
        foreach ($values as $attributeId => $byProduct) {
            $values[$attributeId] = array_filter(array_map(
                fn ($value) => $this->split($attributesById[$attributeId], $value),
                $byProduct
            ));
        }

        return $values;
    }

    /**
     * How many products carry each option of an attribute, in any scope
     *
     * @param array $attribute From attributes(), an option input
     * @return array<int, int> Option id => product count; options without products are left out
     */
    public function optionProductCounts(array $attribute): array
    {
        $counts = [];
        foreach ($this->productsByOption($attribute) as $optionId => $productIds) {
            $counts[$optionId] = count($productIds);
        }

        return $counts;
    }

    /**
     * Products carrying each option, in any scope
     *
     * @param array $attribute From attributes(), an option input
     * @param int[]|null $optionIds Only these options
     * @return array<int, array<int, string>> Option id => product entity id => SKU
     */
    public function productsByOption(array $attribute, ?array $optionIds = null): array
    {
        if (!in_array($attribute['input'], self::OPTION_INPUTS, true) || $attribute['backend_type'] === 'static') {
            return [];
        }
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(
                    ['v' => $this->resource->getTableName('catalog_product_entity_' . $attribute['backend_type'])],
                    ['value']
                )
                ->join(
                    ['e' => $this->resource->getTableName('catalog_product_entity')],
                    'e.' . $this->linkField() . ' = v.' . $this->linkField(),
                    ['entity_id', 'sku']
                )
                ->where('v.attribute_id = ?', $attribute['attribute_id'])
                ->where('v.value IS NOT NULL')
        );

        $byOption = [];
        foreach ($rows as $row) {
            foreach ($this->split($attribute, $row['value']) as $optionId) {
                if ($optionIds === null || in_array((int)$optionId, $optionIds, true)) {
                    $byOption[(int)$optionId][(int)$row['entity_id']] = (string)$row['sku'];
                }
            }
        }

        return $byOption;
    }

    /**
     * Whether configurable products use the attribute to tell their variants apart
     *
     * @param int $attributeId
     * @return bool
     */
    public function isConfigurableAttribute(int $attributeId): bool
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('catalog_product_super_attribute');
        if (!$connection->isTableExists($table)) {
            return false;
        }

        return (bool)$connection->fetchOne(
            $connection->select()
                ->from($table, [new \Zend_Db_Expr('1')])
                ->where('attribute_id = ?', $attributeId)
                ->limit(1)
        );
    }

    /**
     * The column linking EAV value rows to a product: entity_id, or row_id with content staging
     *
     * @return string
     */
    public function linkField(): string
    {
        return $this->linkField ??= $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
    }

    /**
     * A swatch attribute is a select with a swatch type in additional_data
     *
     * @param array $row
     * @return string
     */
    private function input(array $row): string
    {
        $input = (string)$row['frontend_input'];
        if ($input !== 'select' || empty($row['additional_data'])) {
            return $input;
        }
        $swatchType = json_decode((string)$row['additional_data'], true)['swatch_input_type'] ?? '';

        return match ($swatchType) {
            'visual' => 'swatch_visual',
            'text' => 'swatch_text',
            default => $input,
        };
    }

    /**
     * A stored value as a list: a multiselect's option ids, otherwise the value itself
     *
     * @param array $attribute
     * @param mixed $value
     * @return string[]
     */
    private function split(array $attribute, mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if ($attribute['input'] !== 'multiselect') {
            return [(string)$value];
        }

        return array_values(array_filter(array_map('trim', explode(',', (string)$value)), 'strlen'));
    }
}

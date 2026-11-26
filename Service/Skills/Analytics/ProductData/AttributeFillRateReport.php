<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Analytics\ProductData;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\MetadataPool;
use MagoAssistant\Mago\Service\Skills\Catalog\CategoryData\ScopedAttributeJoin;

/**
 * Issue #319: per product attribute, how many of the products that can have it (their attribute set
 * holds it and their type is in its apply_to) have a value. NULL, a blank string and "no_selection"
 * (Magento's "no image") are empty; 0 and "No" are a value.
 *
 * Counts are grouped per attribute, attribute set and product type in one select per value table, so
 * the cost does not grow with the number of attributes and no product is loaded.
 *
 * @phpstan-import-type AttributeInfo from AttributeFillRateAttributes
 * @phpstan-type FillRow array{
 *     attribute_code:string,
 *     label:string,
 *     input_type:string,
 *     value_scope:string,
 *     is_required:bool,
 *     is_custom:bool,
 *     filled_count:int,
 *     applicable_count:int,
 *     empty_count:int,
 *     fill_rate:float
 * }
 * @phpstan-type FillReport array{product_count:int,attributes_without_products:int,attributes:list<FillRow>}
 * @phpstan-type Counts array<int,array<string,int>>
 */
class AttributeFillRateReport
{
    private const NO_IMAGE = 'no_selection';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly MetadataPool $metadataPool,
        private readonly ScopedAttributeJoin $attributeJoin,
        private readonly AttributeFillRateAttributes $attributes
    ) {
    }

    /**
     * @return FillReport Attributes in no particular order
     */
    public function build(AttributeFillRateScope $scope): array
    {
        $setsPerAttribute = $this->setsPerAttribute($scope->attributeSetId);
        $attributes = array_filter(
            $this->attributes->attributes($scope->isIncludingSystem),
            static fn (array $attribute): bool => $scope->attributeSetId === null
                || isset($setsPerAttribute[$attribute['attribute_id']])
        );
        $products = $this->productCounts($scope);
        $filled = $this->filledCounts(array_intersect_key($attributes, $setsPerAttribute), $scope);

        $rows = [];
        foreach ($attributes as $attributeId => $attribute) {
            $sets = $setsPerAttribute[$attributeId] ?? [];
            $applicable = $this->sum($products, $sets, $attribute['apply_to']);
            if ($applicable === 0) {
                continue;
            }
            $rows[] = $this->row($attribute, $this->sum($filled[$attributeId] ?? [], $sets, $attribute['apply_to']), $applicable);
        }

        return [
            'product_count' => (int)array_sum(array_map('array_sum', $products)),
            'attributes_without_products' => count($attributes) - count($rows),
            'attributes' => $rows,
        ];
    }

    /**
     * @return array<int,string> Attribute set id => name
     */
    public function attributeSets(): array
    {
        return $this->attributes->attributeSets();
    }

    /**
     * @return array<int,list<int>> Attribute id => the attribute sets in scope that hold it
     */
    private function setsPerAttribute(?int $attributeSetId): array
    {
        $perAttribute = [];
        foreach ($this->attributes->attributeIdsPerSet() as $setId => $attributeIds) {
            if ($attributeSetId !== null && $setId !== $attributeSetId) {
                continue;
            }
            foreach ($attributeIds as $attributeId) {
                $perAttribute[$attributeId][] = $setId;
            }
        }

        return $perAttribute;
    }

    /**
     * @return Counts
     */
    private function productCounts(AttributeFillRateScope $scope): array
    {
        $select = $this->resource->getConnection()->select()
            ->from(['cpe' => $this->resource->getTableName('catalog_product_entity')], [
                'attribute_set_id',
                'type_id',
                'count' => new \Zend_Db_Expr('COUNT(DISTINCT cpe.entity_id)'),
            ])
            ->group(['cpe.attribute_set_id', 'cpe.type_id']);
        $this->filterProducts($select, $scope);

        return $this->counts($this->resource->getConnection()->fetchAll($select))[0] ?? [];
    }

    /**
     * @param array<int,AttributeInfo> $attributes
     * @return array<int,Counts> Attribute id => counts
     */
    private function filledCounts(array $attributes, AttributeFillRateScope $scope): array
    {
        $filled = [];
        foreach (AttributeFillRateAttributes::BACKEND_TYPES as $backendType) {
            $ofType = array_filter($attributes, static fn (array $attribute): bool => $attribute['backend_type'] === $backendType);
            if ($ofType === []) {
                continue;
            }
            $scoped = array_keys(array_filter($ofType, static fn (array $attribute): bool => $attribute['value_scope'] !== 'global'));
            $selects = [$this->defaultValues($backendType, array_keys($ofType), $scoped, $scope)];
            if ($scope->isStoreView() && $scoped !== []) {
                $selects[] = $this->storeValues($backendType, $scoped, $scope);
            }
            foreach ($selects as $select) {
                $filled = $this->add($filled, $this->counts($this->resource->getConnection()->fetchAll($select)));
            }
        }

        return $filled;
    }

    /**
     * Filled default scope values. In a store view only those it does not override: a store value of
     * NULL falls back to the default, any other store value replaces it.
     *
     * @param list<int> $attributeIds
     * @param list<int> $scopedIds Attributes that can have a store view value
     */
    private function defaultValues(string $backendType, array $attributeIds, array $scopedIds, AttributeFillRateScope $scope): Select
    {
        $select = $this->valueSelect($backendType, $attributeIds, AttributeFillRateScope::DEFAULT_STORE_ID, $scope);
        if (!$scope->isStoreView() || $scopedIds === []) {
            return $select;
        }

        $connection = $this->resource->getConnection();
        $linkField = $this->linkField();
        $select->joinLeft(
            ['s' => $this->valueTable($backendType)],
            sprintf('s.%1$s = v.%1$s AND s.attribute_id = v.attribute_id', $linkField)
                . $connection->quoteInto(' AND s.store_id = ?', $scope->storeId)
                . $connection->quoteInto(' AND v.attribute_id IN (?)', $scopedIds),
            []
        )->where('s.value IS NULL');

        return $select;
    }

    /**
     * Filled store view values of the attributes that can have one.
     *
     * @param list<int> $scopedIds
     */
    private function storeValues(string $backendType, array $scopedIds, AttributeFillRateScope $scope): Select
    {
        return $this->valueSelect($backendType, $scopedIds, $scope->storeId, $scope);
    }

    /**
     * @param list<int> $attributeIds
     */
    private function valueSelect(string $backendType, array $attributeIds, int $storeId, AttributeFillRateScope $scope): Select
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['v' => $this->valueTable($backendType)], [
                'attribute_id',
                'attribute_set_id' => 'cpe.attribute_set_id',
                'type_id' => 'cpe.type_id',
                'count' => new \Zend_Db_Expr('COUNT(DISTINCT cpe.entity_id)'),
            ])
            ->join(
                ['cpe' => $this->resource->getTableName('catalog_product_entity')],
                sprintf('cpe.%1$s = v.%1$s', $this->linkField()),
                []
            )
            ->where('v.store_id = ?', $storeId)
            ->where('v.attribute_id IN (?)', $attributeIds)
            ->where('v.value IS NOT NULL')
            ->group(['v.attribute_id', 'cpe.attribute_set_id', 'cpe.type_id']);
        if (in_array($backendType, ['varchar', 'text'], true)) {
            $select->where("TRIM(v.value) <> ''")->where('v.value <> ?', self::NO_IMAGE);
        }
        $this->filterProducts($select, $scope);

        return $select;
    }

    private function filterProducts(Select $select, AttributeFillRateScope $scope): void
    {
        if ($scope->attributeSetId !== null) {
            $select->where('cpe.attribute_set_id = ?', $scope->attributeSetId);
        }
        if ($scope->isStoreView()) {
            $select->join(
                ['cpw' => $this->resource->getTableName('catalog_product_website')],
                $this->resource->getConnection()->quoteInto(
                    'cpw.product_id = cpe.entity_id AND cpw.website_id = ?',
                    $scope->websiteId
                ),
                []
            );
        }
        if ($scope->isOnlyEnabled) {
            $status = $this->attributeJoin->join($select, Product::ENTITY, 'status', 'cpe', $this->linkField(), $scope->storeId);
            $select->where($status . ' = ?', Status::STATUS_ENABLED);
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<int,Counts> Attribute id (0 for product counts) => counts
     */
    private function counts(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int)($row['attribute_id'] ?? 0)][(int)$row['attribute_set_id']][(string)$row['type_id']] = (int)$row['count'];
        }

        return $counts;
    }

    /**
     * @param array<int,Counts> $total
     * @param array<int,Counts> $more
     * @return array<int,Counts>
     */
    private function add(array $total, array $more): array
    {
        foreach ($more as $attributeId => $perSet) {
            foreach ($perSet as $setId => $perType) {
                foreach ($perType as $type => $count) {
                    $total[$attributeId][$setId][$type] = ($total[$attributeId][$setId][$type] ?? 0) + $count;
                }
            }
        }

        return $total;
    }

    /**
     * @param Counts $counts
     * @param list<int> $sets
     * @param list<string> $applyTo Product types, all when empty
     */
    private function sum(array $counts, array $sets, array $applyTo): int
    {
        $total = 0;
        foreach (array_intersect_key($counts, array_flip($sets)) as $perType) {
            $total += array_sum($applyTo === [] ? $perType : array_intersect_key($perType, array_flip($applyTo)));
        }

        return $total;
    }

    /**
     * @param AttributeInfo $attribute
     * @return FillRow
     */
    private function row(array $attribute, int $filled, int $applicable): array
    {
        return [
            'attribute_code' => $attribute['attribute_code'],
            'label' => $attribute['label'],
            'input_type' => $attribute['input_type'],
            'value_scope' => $attribute['value_scope'],
            'is_required' => $attribute['is_required'],
            'is_custom' => $attribute['is_custom'],
            'filled_count' => $filled,
            'applicable_count' => $applicable,
            'empty_count' => $applicable - $filled,
            'fill_rate' => round($filled / $applicable * 100, 1),
        ];
    }

    private function valueTable(string $backendType): string
    {
        return $this->resource->getTableName('catalog_product_entity_' . $backendType);
    }

    private function linkField(): string
    {
        return $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
    }
}

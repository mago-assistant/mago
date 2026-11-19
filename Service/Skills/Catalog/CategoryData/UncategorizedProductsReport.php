<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\CategoryData;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\MetadataPool;
use MagoAssistant\Mago\Service\Skills\ResultLimit;

/**
 * Finds the products that no enabled category of a store view leads to: not linked to any category,
 * linked only to the root or to categories of another store's root, or linked only to categories
 * that are disabled in the store view. Live products are the ones a shopper would otherwise reach
 * through the menu: enabled, in the website and visible in the catalog.
 *
 * @phpstan-type ProductDetails array{sku:string,name:string,type:string,status:string,visibility:string}
 * @phpstan-type StoreResult array{count:int,reasons:array<int,string>}
 */
class UncategorizedProductsReport
{
    public const REASON_NO_CATEGORY = 'no_category';
    public const REASON_ROOT_ONLY = 'root_only';
    public const REASON_DISABLED_ONLY = 'disabled_categories_only';

    /** @var array<string,StoreResult> Results of store views that show the same products and tree */
    private array $results = [];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly MetadataPool $metadataPool,
        private readonly Visibility $visibility,
        private readonly CategoryTree $categoryTree,
        private readonly ScopedAttributeJoin $attributeJoin
    ) {
    }

    /**
     * How many products have no enabled category in the store view, and the first of them by SKU.
     *
     * Only the listed products are fetched, so a large catalog never loads every id into memory.
     *
     * @return StoreResult Count, and product id => reason for at most $limit products
     */
    public function forStore(int $storeId, int $websiteId, int $rootCategoryId, bool $onlyLive, int $limit): array
    {
        $tree = $this->categoryTree->below($storeId, $rootCategoryId);
        $treeIds = array_keys($tree);
        $activeIds = array_keys(array_filter($tree, static fn (array $category): bool => $category['is_active']));

        // Store views without their own status or visibility values show the default scope ones, so with
        // the same website and enabled categories they share one result: the scan is the expensive part.
        $scopeStoreId = $onlyLive
            && $this->attributeJoin->hasStoreValues(Product::ENTITY, ['status', 'visibility'], $storeId)
                ? $storeId
                : 0;
        $limit = max(1, min($limit, ResultLimit::MAX));
        $key = implode('/', [
            $websiteId,
            $rootCategoryId,
            (int)$onlyLive,
            $scopeStoreId,
            $limit,
            implode(',', $activeIds),
        ]);

        return $this->results[$key] ??= $this->scan($scopeStoreId, $websiteId, $treeIds, $activeIds, $onlyLive, $limit);
    }

    /**
     * @param list<int> $treeIds
     * @param list<int> $activeIds
     * @return StoreResult
     */
    private function scan(
        int $storeId,
        int $websiteId,
        array $treeIds,
        array $activeIds,
        bool $onlyLive,
        int $limit
    ): array {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['cpe' => $this->resource->getTableName('catalog_product_entity')], ['entity_id'])
            ->columns(['reason' => new \Zend_Db_Expr(sprintf(
                'CASE WHEN NOT %s THEN %s WHEN NOT %s THEN %s ELSE %s END',
                $this->linkedTo(null),
                $connection->quote(self::REASON_NO_CATEGORY),
                $this->linkedTo($treeIds),
                $connection->quote(self::REASON_ROOT_ONLY),
                $connection->quote(self::REASON_DISABLED_ONLY)
            ))])
            ->where('NOT ' . $this->linkedTo($activeIds))
            ->order('cpe.sku ASC');

        if ($onlyLive) {
            $this->onlyLive($select, $storeId, $websiteId);
        }

        $count = clone $select;
        $count->reset(Select::COLUMNS)->reset(Select::ORDER)->columns(['count' => new \Zend_Db_Expr('COUNT(*)')]);

        $reasons = [];
        foreach ($connection->fetchPairs($select->limit($limit)) as $productId => $reason) {
            $reasons[(int)$productId] = (string)$reason;
        }

        return ['count' => (int)$connection->fetchOne($count), 'reasons' => $reasons];
    }

    /**
     * SKU, name, type, status and visibility of the given products in a store view.
     *
     * @param list<int> $productIds
     * @return array<int,ProductDetails>
     */
    public function details(array $productIds, int $storeId): array
    {
        if ($productIds === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['cpe' => $this->resource->getTableName('catalog_product_entity')], ['entity_id', 'sku', 'type_id'])
            ->where('cpe.entity_id IN (?)', $productIds);

        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $values = [];
        foreach (['name', 'status', 'visibility'] as $code) {
            $values[$code] = $this->attributeJoin->join($select, Product::ENTITY, $code, 'cpe', $linkField, $storeId);
        }
        $select->columns([
            'name' => new \Zend_Db_Expr(sprintf('COALESCE(%s, \'\')', $values['name'])),
            'status' => new \Zend_Db_Expr($values['status']),
            'visibility' => new \Zend_Db_Expr($values['visibility']),
        ]);

        $details = [];
        foreach ($connection->fetchAll($select) as $row) {
            $details[(int)$row['entity_id']] = [
                'sku' => (string)$row['sku'],
                'name' => (string)$row['name'],
                'type' => (string)$row['type_id'],
                'status' => (int)$row['status'] === Status::STATUS_ENABLED ? 'enabled' : 'disabled',
                'visibility' => (string)$this->visibility->getOptionText((int)$row['visibility']),
            ];
        }

        return $details;
    }

    /**
     * Enabled and visible in the catalog in the store view, and in its website.
     */
    private function onlyLive(Select $select, int $storeId, int $websiteId): void
    {
        $connection = $this->resource->getConnection();
        $select->join(
            ['cpw' => $this->resource->getTableName('catalog_product_website')],
            $connection->quoteInto('cpw.product_id = cpe.entity_id AND cpw.website_id = ?', $websiteId),
            []
        );

        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $status = $this->attributeJoin->join($select, Product::ENTITY, 'status', 'cpe', $linkField, $storeId);
        $visibility = $this->attributeJoin->join($select, Product::ENTITY, 'visibility', 'cpe', $linkField, $storeId);
        $select->where($status . ' = ?', Status::STATUS_ENABLED)
            ->where($visibility . ' IN (?)', $this->visibility->getVisibleInCatalogIds());
    }

    /**
     * SQL condition: the product is linked to one of the categories, or to any category when null.
     *
     * @param list<int>|null $categoryIds
     */
    private function linkedTo(?array $categoryIds): string
    {
        if ($categoryIds === []) {
            return '(1 = 0)';
        }

        $connection = $this->resource->getConnection();
        $links = $connection->select()
            ->from(['ccp' => $this->resource->getTableName('catalog_category_product')], [new \Zend_Db_Expr('1')])
            ->where('ccp.product_id = cpe.entity_id');
        if ($categoryIds !== null) {
            $links->where('ccp.category_id IN (?)', $categoryIds);
        }

        return 'EXISTS (' . $links . ')';
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\CategoryData;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Indexer\Category\Product\TableMaintainer;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * The categories below a store's root category with their store view values, in tree order.
 *
 * @phpstan-type CategoryNode array{path:string,level:int,name:string,is_active:bool,is_anchor:bool,
 *     display_mode:string}
 */
class CategoryTree
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly MetadataPool $metadataPool,
        private readonly ScopedAttributeJoin $attributeJoin,
        private readonly TableMaintainer $categoryIndexTables,
        private readonly Visibility $visibility
    ) {
    }

    /**
     * Root categories sit directly below the tree root (id 1).
     */
    public static function rootPath(int $rootCategoryId): string
    {
        return Category::TREE_ROOT_ID . '/' . $rootCategoryId;
    }

    /**
     * Every category below the root, the root itself left out, falling back to the default scope.
     *
     * @return array<int,CategoryNode>
     */
    public function below(int $storeId, int $rootCategoryId): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['cc' => $this->resource->getTableName('catalog_category_entity')], ['entity_id', 'path', 'level'])
            ->where('cc.path LIKE ?', self::rootPath($rootCategoryId) . '/%')
            ->order('cc.path ASC');

        $linkField = $this->metadataPool->getMetadata(CategoryInterface::class)->getLinkField();
        $values = [];
        foreach (['name', 'is_active', 'is_anchor', 'display_mode'] as $code) {
            $values[$code] = $this->attributeJoin->join($select, Category::ENTITY, $code, 'cc', $linkField, $storeId);
        }
        $select->columns([
            'name' => new \Zend_Db_Expr(sprintf('COALESCE(%s, \'\')', $values['name'])),
            'is_active' => new \Zend_Db_Expr(sprintf('COALESCE(%s, 0)', $values['is_active'])),
            'is_anchor' => new \Zend_Db_Expr(sprintf('COALESCE(%s, 0)', $values['is_anchor'])),
            'display_mode' => new \Zend_Db_Expr(sprintf('COALESCE(%s, \'\')', $values['display_mode'])),
        ]);

        $categories = [];
        foreach ($connection->fetchAll($select) as $row) {
            $categories[(int)$row['entity_id']] = [
                'path' => (string)$row['path'],
                'level' => (int)$row['level'],
                'name' => (string)$row['name'],
                'is_active' => (int)$row['is_active'] === 1,
                'is_anchor' => (int)$row['is_anchor'] === 1,
                'display_mode' => (string)$row['display_mode'],
            ];
        }

        return $categories;
    }

    /**
     * Products the storefront lists in each category, from the store view's category product index:
     * enabled, in the website, visible in the catalog, and for an anchor category its subcategories' too
     *
     * @param int $storeId
     * @param int[] $categoryIds
     * @return array<int, int> Category id => product count
     */
    public function storefrontProductCounts(int $storeId, array $categoryIds): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->categoryIndexTables->getMainTable($storeId);
        if ($categoryIds === [] || !$connection->isTableExists($table)) {
            return [];
        }

        return array_map('intval', $connection->fetchPairs(
            $connection->select()
                ->from($table, ['category_id', 'products' => new \Zend_Db_Expr('COUNT(DISTINCT product_id)')])
                ->where('category_id IN (?)', $categoryIds)
                ->where('visibility IN (?)', $this->visibility->getVisibleInCatalogIds())
                ->group('category_id')
        ));
    }
}

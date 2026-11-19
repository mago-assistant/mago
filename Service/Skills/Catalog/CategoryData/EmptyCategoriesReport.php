<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\CategoryData;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Finds the enabled categories of a store view that show no products on the storefront.
 *
 * A product shows in a category when it is enabled and visible in the catalog in that store view
 * and assigned to the store's website. An anchor category also shows the products of its enabled
 * subcategories, the way Magento's category product indexer builds it. When the store hides
 * out-of-stock products, a product must also be salable in the website's stock. The admin's product
 * count per category counts every assigned product, so it cannot answer this.
 *
 * @phpstan-import-type CategoryNode from CategoryTree
 * @phpstan-type ProductCounts array{live:array<int,int>,shown:array<int,int>}
 * @phpstan-type EmptyCategoryRow array{category_id:int,name:string,path:string,level:int,is_anchor:bool,
 *     display_mode:string,assigned_products:int,subcategories:int,reason:string}
 */
class EmptyCategoriesReport
{
    public const REASON_NONE_ASSIGNED = 'no_products_assigned';
    public const REASON_NONE_LIVE = 'no_live_products';
    public const REASON_STATIC_BLOCK = 'static_block_only';
    public const REASON_OUT_OF_STOCK = 'out_of_stock_only';

    private const PATH_SEPARATOR = ' > ';

    /** @var array<int,array<int,int>> Root category id => assigned product counts, the same for every store view */
    private array $assignedCounts = [];

    /** @var array<string,ProductCounts> "website/root/scope store" => product counts */
    private array $liveCounts = [];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly MetadataPool $metadataPool,
        private readonly Visibility $visibility,
        private readonly SalableProducts $salableProducts,
        private readonly CategoryTree $categoryTree,
        private readonly ScopedAttributeJoin $attributeJoin
    ) {
    }

    /**
     * Enabled categories under the store's root category that show no products, in tree order.
     *
     * @return array{active_categories:int,empty:list<EmptyCategoryRow>}
     */
    public function forStore(int $storeId, int $websiteId, int $rootCategoryId): array
    {
        $categories = $this->categoryTree->below($storeId, $rootCategoryId);
        if ($categories === []) {
            return ['active_categories' => 0, 'empty' => []];
        }

        $counts = $this->cachedProductCounts($storeId, $websiteId, $rootCategoryId);

        return [
            'active_categories' => count(array_filter($categories, static fn (array $c): bool => $c['is_active'])),
            'empty' => self::emptyCategories(
                $categories,
                $counts['live'],
                $counts['shown'],
                $this->assignedCounts[$rootCategoryId] ??= $this->assignedProductCounts($rootCategoryId),
                $rootCategoryId
            ),
        ];
    }

    /**
     * @param array<int,CategoryNode> $categories Category id => node, every category under the root
     * @param array<int,int> $liveCounts Category id => products assigned to it that are enabled, visible
     *        in the catalog and in the website
     * @param array<int,int> $shownCounts Category id => those of them the storefront shows: the in-stock
     *        ones when out-of-stock products are hidden, otherwise the same as $liveCounts
     * @param array<int,int> $assignedCounts Category id => products assigned to it, any status
     * @return list<EmptyCategoryRow>
     */
    public static function emptyCategories(
        array $categories,
        array $liveCounts,
        array $shownCounts,
        array $assignedCounts,
        int $rootCategoryId
    ): array {
        // The anchor indexer adds the products of enabled categories to every category above them.
        $liveFromBelow = self::reachedFromBelow($categories, $liveCounts);
        $shownFromBelow = self::reachedFromBelow($categories, $shownCounts);
        $subcategories = [];
        foreach ($categories as $id => $category) {
            $ancestors = self::ancestorIds($category['path'], $id);
            $parentId = end($ancestors);
            if ($category['is_active'] && $parentId !== false) {
                $subcategories[$parentId] = ($subcategories[$parentId] ?? 0) + 1;
            }
        }

        $rows = [];
        foreach ($categories as $id => $category) {
            if (!$category['is_active'] || ($shownCounts[$id] ?? 0) > 0) {
                continue;
            }
            if ($category['is_anchor'] && isset($shownFromBelow[$id])) {
                continue;
            }

            $hasLive = ($liveCounts[$id] ?? 0) > 0 || ($category['is_anchor'] && isset($liveFromBelow[$id]));

            $assigned = $assignedCounts[$id] ?? 0;
            $rows[] = [
                'category_id' => $id,
                'name' => $category['name'],
                'path' => self::breadcrumb($categories, $category['path'], $id, $rootCategoryId),
                'level' => $category['level'],
                'is_anchor' => $category['is_anchor'],
                'display_mode' => $category['display_mode'],
                'assigned_products' => $assigned,
                'subcategories' => $subcategories[$id] ?? 0,
                'reason' => self::reason($category['display_mode'], $hasLive, $assigned),
            ];
        }

        return $rows;
    }

    /**
     * Ids of every category above an enabled category that has products in the given counts.
     *
     * @param array<int,CategoryNode> $categories
     * @param array<int,int> $counts
     * @return array<int,true>
     */
    private static function reachedFromBelow(array $categories, array $counts): array
    {
        $reached = [];
        foreach ($categories as $id => $category) {
            if ($category['is_active'] && ($counts[$id] ?? 0) > 0) {
                foreach (self::ancestorIds($category['path'], $id) as $ancestorId) {
                    $reached[$ancestorId] = true;
                }
            }
        }

        return $reached;
    }

    private static function reason(string $displayMode, bool $hasLive, int $assigned): string
    {
        if ($displayMode === Category::DM_PAGE) {
            return self::REASON_STATIC_BLOCK;
        }
        if ($hasLive) {
            return self::REASON_OUT_OF_STOCK;
        }

        return $assigned > 0 ? self::REASON_NONE_LIVE : self::REASON_NONE_ASSIGNED;
    }

    /**
     * Ids in a category path above the category itself, root first.
     *
     * @return list<int>
     */
    private static function ancestorIds(string $path, int $categoryId): array
    {
        $ids = array_map('intval', explode('/', $path));

        return array_values(array_filter($ids, static fn (int $id): bool => $id !== $categoryId));
    }

    /**
     * Names from below the store root down to the category, e.g. "Men > Shoes > Sale".
     *
     * @param array<int,CategoryNode> $categories
     */
    private static function breadcrumb(array $categories, string $path, int $categoryId, int $rootCategoryId): string
    {
        $names = [];
        foreach ([...self::ancestorIds($path, $categoryId), $categoryId] as $id) {
            if (isset($categories[$id]) && $id !== $rootCategoryId) {
                $names[] = $categories[$id]['name'];
            }
        }

        return implode(self::PATH_SEPARATOR, $names);
    }

    /**
     * Store views without their own status or visibility values show what the default scope shows, so
     * those share one count per website and root: the count is the expensive query here.
     *
     * @return ProductCounts
     */
    private function cachedProductCounts(int $storeId, int $websiteId, int $rootCategoryId): array
    {
        $scopeStoreId = $this->attributeJoin->hasStoreValues(Product::ENTITY, ['status', 'visibility'], $storeId)
            ? $storeId
            : 0;

        return $this->liveCounts[$websiteId . '/' . $rootCategoryId . '/' . $scopeStoreId]
            ??= $this->productCounts($scopeStoreId, $websiteId, $rootCategoryId);
    }

    /**
     * Products per category that are enabled and visible in the catalog in the store view and
     * assigned to its website, and how many of them the storefront shows. Both come from one pass.
     *
     * @return ProductCounts
     */
    private function productCounts(int $storeId, int $websiteId, int $rootCategoryId): array
    {
        $connection = $this->resource->getConnection();
        $select = $this->assignmentsUnderRoot($rootCategoryId)
            ->join(
                ['cpe' => $this->resource->getTableName('catalog_product_entity')],
                'cpe.entity_id = ccp.product_id',
                []
            )
            ->join(
                ['cpw' => $this->resource->getTableName('catalog_product_website')],
                $connection->quoteInto('cpw.product_id = ccp.product_id AND cpw.website_id = ?', $websiteId),
                []
            )
            ->columns(['live' => new \Zend_Db_Expr('COUNT(DISTINCT ccp.product_id)')]);

        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $status = $this->attributeJoin->join($select, Product::ENTITY, 'status', 'cpe', $linkField, $storeId);
        $visibility = $this->attributeJoin->join($select, Product::ENTITY, 'visibility', 'cpe', $linkField, $storeId);
        $select->where($status . ' = ?', Status::STATUS_ENABLED)
            ->where($visibility . ' IN (?)', $this->visibility->getVisibleInCatalogIds());

        $shown = 'live';
        if ($this->salableProducts->hidesOutOfStock()) {
            $salable = $this->salableProducts->joinSalable($select, $websiteId);
            $select->columns(['shown' => new \Zend_Db_Expr(
                sprintf('COUNT(DISTINCT CASE WHEN %s THEN ccp.product_id END)', $salable)
            )]);
            $shown = 'shown';
        }

        $counts = ['live' => [], 'shown' => []];
        foreach ($connection->fetchAll($select) as $row) {
            $categoryId = (int)$row['category_id'];
            $counts['live'][$categoryId] = (int)$row['live'];
            $counts['shown'][$categoryId] = (int)$row[$shown];
        }

        return $counts;
    }

    /**
     * Products per category whatever their status, visibility or website.
     *
     * @return array<int,int>
     */
    private function assignedProductCounts(int $rootCategoryId): array
    {
        return $this->countsByCategory(
            $this->assignmentsUnderRoot($rootCategoryId)
                ->columns(['assigned' => new \Zend_Db_Expr('COUNT(*)')])
        );
    }

    private function assignmentsUnderRoot(int $rootCategoryId): Select
    {
        return $this->resource->getConnection()->select()
            ->from(['ccp' => $this->resource->getTableName('catalog_category_product')], ['category_id'])
            ->join(
                ['cc' => $this->resource->getTableName('catalog_category_entity')],
                'cc.entity_id = ccp.category_id',
                []
            )
            ->where('cc.path LIKE ?', CategoryTree::rootPath($rootCategoryId) . '/%')
            ->group('ccp.category_id');
    }

    /**
     * @return array<int,int>
     */
    private function countsByCategory(Select $select): array
    {
        $counts = [];
        foreach ($this->resource->getConnection()->fetchPairs($select) as $categoryId => $count) {
            $counts[(int)$categoryId] = (int)$count;
        }

        return $counts;
    }
}

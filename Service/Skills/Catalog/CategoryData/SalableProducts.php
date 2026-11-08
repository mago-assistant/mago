<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\CategoryData;

use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Model\Stock;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\ObjectManagerInterface;
use Magento\InventoryIndexer\Model\StockIndexTableNameResolverInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\Store\Model\StoreManagerInterface;
use MagoAssistant\Mago\Service\Skills\Inventory\MsiAvailability;

/**
 * Whether the storefront hides out-of-stock products, and the stock index that says which products of
 * a website are salable: the legacy stock status, or with Multi-Source Inventory the index of the
 * stock assigned to the website. MSI services are resolved at run time, so the class loads without them.
 */
class SalableProducts
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StockConfigurationInterface $stockConfiguration,
        private readonly MsiAvailability $msiAvailability,
        private readonly StoreManagerInterface $storeManager,
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    /**
     * "Display Out of Stock Products" is a global setting.
     */
    public function hidesOutOfStock(): bool
    {
        return !$this->stockConfiguration->isShowOutOfStock();
    }

    /**
     * Left joins the stock index of the website on a select holding catalog_product_entity as "cpe".
     *
     * @return string SQL condition that is true for a salable product
     */
    public function joinSalable(Select $select, int $websiteId): string
    {
        $connection = $this->resource->getConnection();
        if (!$this->msiAvailability->isEnabled()) {
            $select->joinLeft(
                ['stock' => $this->resource->getTableName('cataloginventory_stock_status')],
                $connection->quoteInto(
                    'stock.product_id = cpe.entity_id AND stock.website_id = ?',
                    $this->stockConfiguration->getDefaultScopeId()
                ) . $connection->quoteInto(' AND stock.stock_id = ?', Stock::DEFAULT_STOCK_ID),
                []
            );

            return 'stock.stock_status = 1';
        }

        $select->joinLeft(['stock' => $this->msiStockTable($websiteId)], 'stock.sku = cpe.sku', []);

        return 'stock.is_salable = 1';
    }

    private function msiStockTable(int $websiteId): string
    {
        $websiteCode = (string)$this->storeManager->getWebsite($websiteId)->getCode();
        /** @var StockResolverInterface $stockResolver */
        $stockResolver = $this->objectManager->get(StockResolverInterface::class);
        /** @var StockIndexTableNameResolverInterface $tableNameResolver */
        $tableNameResolver = $this->objectManager->get(StockIndexTableNameResolverInterface::class);

        $stockId = (int)$stockResolver->execute(SalesChannelInterface::TYPE_WEBSITE, $websiteCode)->getStockId();

        return $tableNameResolver->execute($stockId);
    }
}

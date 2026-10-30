<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Catalog\CategoryData;

use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\InventoryApi\Api\Data\StockInterface;
use Magento\InventoryIndexer\Model\StockIndexTableNameResolverInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use MagoAssistant\Mago\Service\Skills\Catalog\CategoryData\SalableProducts;
use MagoAssistant\Mago\Service\Skills\Inventory\MsiAvailability;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SalableProductsTest extends TestCase
{
    #[Test]
    public function outOfStockProductsAreHiddenWhenTheStoreDoesNotDisplayThem(): void
    {
        self::assertTrue($this->salableProducts(showOutOfStock: false)->hidesOutOfStock());
        self::assertFalse($this->salableProducts(showOutOfStock: true)->hidesOutOfStock());
    }

    #[Test]
    public function withoutMsiTheLegacyStockStatusOfTheDefaultScopeIsJoined(): void
    {
        $joins = [];
        $condition = $this->salableProducts(msi: false)->joinSalable($this->select($joins), 1);

        self::assertSame('stock.stock_status = 1', $condition);
        self::assertSame(
            [
                'cataloginventory_stock_status',
                'stock.product_id = cpe.entity_id AND stock.website_id = 0 AND stock.stock_id = 1',
            ],
            $joins
        );
    }

    /**
     * With MSI the website's sales channel decides the stock, and that stock's index holds is_salable per SKU.
     */
    #[Test]
    public function withMsiTheIndexOfTheWebsitesStockIsJoinedBySku(): void
    {
        $joins = [];
        $condition = $this->salableProducts(msi: true)->joinSalable($this->select($joins), 2);

        self::assertSame('stock.is_salable = 1', $condition);
        self::assertSame(['inventory_stock_3', 'stock.sku = cpe.sku'], $joins);
    }

    /**
     * @param list<string> $joins Filled with the joined table and condition
     */
    private function select(array &$joins): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('joinLeft')->willReturnCallback(
            static function (array $table, string $condition) use (&$joins, $select): Select {
                $joins = [(string)reset($table), $condition];
                return $select;
            }
        );

        return $select;
    }

    private function salableProducts(bool $showOutOfStock = false, bool $msi = false): SalableProducts
    {
        $adapter = $this->createStub(Mysql::class);
        $adapter->method('quoteInto')->willReturnCallback(
            static fn (string $text, mixed $value): string => str_replace('?', (string)$value, $text)
        );
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($adapter);
        $resource->method('getTableName')->willReturnArgument(0);

        $stockConfiguration = $this->createStub(StockConfigurationInterface::class);
        $stockConfiguration->method('isShowOutOfStock')->willReturn($showOutOfStock);
        $stockConfiguration->method('getDefaultScopeId')->willReturn(0);

        $modules = $this->createStub(ModuleManager::class);
        $modules->method('isEnabled')->willReturn($msi);

        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getCode')->willReturn('nl_site');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getWebsite')->willReturnCallback(
            static fn (int $id): WebsiteInterface => $id === 2 ? $website : throw new \LogicException('website ' . $id)
        );

        $stock = $this->createStub(StockInterface::class);
        $stock->method('getStockId')->willReturn(3);
        $stockResolver = $this->createStub(StockResolverInterface::class);
        $stockResolver->method('execute')->willReturnCallback(
            static fn (string $type, string $code): StockInterface => $type === 'website' && $code === 'nl_site'
                ? $stock
                : throw new \LogicException($type . ' ' . $code)
        );
        $tableNameResolver = $this->createStub(StockIndexTableNameResolverInterface::class);
        $tableNameResolver->method('execute')->willReturnCallback(
            static fn (int $id): string => 'inventory_stock_' . $id
        );

        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnMap([
            [StockResolverInterface::class, $stockResolver],
            [StockIndexTableNameResolverInterface::class, $tableNameResolver],
        ]);

        return new SalableProducts(
            $resource,
            $stockConfiguration,
            new MsiAvailability($modules),
            $storeManager,
            $objectManager
        );
    }
}

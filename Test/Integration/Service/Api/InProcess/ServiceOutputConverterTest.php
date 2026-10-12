<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Integration\Service\Api\InProcess;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\Data\StockItemInterfaceFactory;
use Magento\Framework\ObjectManagerInterface;
use MagoAssistant\Mago\Service\Api\InProcess\ResolvedRoute;
use MagoAssistant\Mago\Service\Api\InProcess\ServiceOutputConverter;
use MagoAssistant\Mago\Test\Integration\Fakes\FakeAuthorization;
use MagoAssistant\Mago\Test\Integration\MagentoObjectManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The field-level ACL REST applies to a service's output, on Magento's real extension attribute config:
 * a product's stock_item is declared with the Magento_CatalogInventory::cataloginventory resource. The
 * product is only built in memory; nothing is saved.
 */
final class ServiceOutputConverterTest extends TestCase
{
    private const STOCK_RESOURCE = 'Magento_CatalogInventory::cataloginventory';

    private ObjectManagerInterface $objectManager;

    protected function setUp(): void
    {
        $this->objectManager = MagentoObjectManager::get();
    }

    #[Test]
    public function itLeavesTheStockItemOutForAnAdminWhoseRoleLacksInventoryAccess(): void
    {
        $output = $this->convert(new FakeAuthorization([]));

        self::assertArrayNotHasKey('stock_item', $output['extension_attributes'] ?? []);
        self::assertSame('mago-output-acl', $output['sku']);
    }

    #[Test]
    public function itGivesTheStockItemToAnAdminWhoseRoleHasInventoryAccess(): void
    {
        $output = $this->convert(new FakeAuthorization([self::STOCK_RESOURCE => true]));

        self::assertSame(5.0, (float)($output['extension_attributes']['stock_item']['qty'] ?? 0));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function convert(FakeAuthorization $authorization): array
    {
        return $this->objectManager->create(ServiceOutputConverter::class)->convert(
            $this->productWithStock(),
            new ResolvedRoute(ProductRepositoryInterface::class, 'get', '/V1/products/:sku', ['Magento_Catalog::products'], []),
            $authorization
        );
    }

    private function productWithStock(): ProductInterface
    {
        $product = $this->objectManager->get(ProductInterfaceFactory::class)->create();
        $product->setSku('mago-output-acl');
        $product->setName('Output ACL');
        $product->getExtensionAttributes()->setStockItem(
            $this->objectManager->get(StockItemInterfaceFactory::class)->create()->setQty(5)
        );

        return $product;
    }
}

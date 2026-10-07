<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Integration\Service\Api\InProcess;

use Magento\Catalog\Model\Product\AuthorizationFactory as ProductAuthorizationFactory;
use Magento\Cms\Model\Page\AuthorizationFactory as PageAuthorizationFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\ObjectManagerInterface;
use MagoAssistant\Mago\Service\Api\InProcess\ApiCall;
use MagoAssistant\Mago\Service\Api\InProcess\ConnectionTransaction;
use MagoAssistant\Mago\Service\Api\InProcess\ConnectionTransactionInterface;
use MagoAssistant\Mago\Service\Api\InProcess\ExceptionMasker;
use MagoAssistant\Mago\Service\Api\InProcess\ExceptionMaskerInterface;
use MagoAssistant\Mago\Service\Api\InProcess\Guard\CmsPageDesignGuard;
use MagoAssistant\Mago\Service\Api\InProcess\Guard\ProductDesignGuard;
use MagoAssistant\Mago\Service\Api\InProcess\ServiceDispatcher;
use MagoAssistant\Mago\Test\Integration\MagentoObjectManager;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAdminAuthorizationFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pushes design changes through the real dispatcher, ServiceInputProcessor and Magento's own design
 * checks. The bodies use the spellings a raw-body check would miss: "Page_Layout", which Magento turns
 * into setPageLayout(), and custom attributes sent as a code => value map. Every call runs
 * inside a transaction that is rolled back, so an allowed save leaves nothing behind.
 */
final class DesignGuardTest extends TestCase
{
    private const PAGE_ROUTE_RESOURCES = ['Magento_Cms::save'];
    private const PRODUCT_ROUTE_RESOURCES = ['Magento_Catalog::products'];
    private const PAGE_LAYOUT = 'empty';
    private const ADMIN_USER_ID = 1;
    private const DEFAULT_ATTRIBUTE_SET_ID = 4;

    private ObjectManagerInterface $objectManager;
    private AdapterInterface $connection;

    protected function setUp(): void
    {
        $this->objectManager = MagentoObjectManager::get();
        $this->objectManager->configure([
            'preferences' => [
                ExceptionMaskerInterface::class => ExceptionMasker::class,
                ConnectionTransactionInterface::class => ConnectionTransaction::class,
            ],
        ]);
        $this->connection = $this->objectManager->get(ResourceConnection::class)->getConnection();
    }

    #[Test]
    public function itRefusesAPageLayoutKeyInMixedCaseToAnAdminWithoutTheSaveDesignPermission(): void
    {
        $result = $this->dispatchRolledBack(self::PAGE_ROUTE_RESOURCES, $this->pageCall());

        self::assertSame(
            ['error' => 'You are not allowed to change CMS pages design settings. '
                . 'This needs the Magento_Cms::save_design permission.'],
            $result
        );
    }

    #[Test]
    public function itSavesAPageLayoutKeyInMixedCaseForAnAdminWithTheSaveDesignPermission(): void
    {
        $result = $this->dispatchRolledBack(
            [...self::PAGE_ROUTE_RESOURCES, CmsPageDesignGuard::ACL_RESOURCE],
            $this->pageCall()
        );

        self::assertArrayNotHasKey('error', $result);
        self::assertSame(self::PAGE_LAYOUT, $result['page_layout'] ?? null);
    }

    #[Test]
    public function itRefusesACustomAttributeMapDesignChangeToAnAdminWithoutTheEditProductDesignPermission(): void
    {
        $result = $this->dispatchRolledBack(self::PRODUCT_ROUTE_RESOURCES, $this->productCall());

        self::assertSame(
            ['error' => 'Not allowed to edit the product\'s design attributes. '
                . 'This needs the Magento_Catalog::edit_product_design permission.'],
            $result
        );
    }

    #[Test]
    public function itSavesACustomAttributeMapDesignChangeForAnAdminWithTheEditProductDesignPermission(): void
    {
        $result = $this->dispatchRolledBack(
            [...self::PRODUCT_ROUTE_RESOURCES, ProductDesignGuard::ACL_RESOURCE],
            $this->productCall()
        );

        self::assertArrayNotHasKey('error', $result);
        self::assertContains(
            ['attribute_code' => 'page_layout', 'value' => '1column'],
            $result['custom_attributes'] ?? []
        );
    }

    /**
     * @param string[] $allowedResources
     * @return array<array-key, mixed>
     */
    private function dispatchRolledBack(array $allowedResources, ApiCall $call): array
    {
        $this->connection->beginTransaction();

        try {
            return $this->dispatcher($allowedResources)->dispatch($call);
        } finally {
            $this->connection->rollBack();
        }
    }

    /**
     * @param string[] $allowedResources
     */
    private function dispatcher(array $allowedResources): ServiceDispatcher
    {
        return $this->objectManager->create(ServiceDispatcher::class, [
            'authorizationFactory' => new FakeAdminAuthorizationFactory($allowedResources),
            'guards' => [
                new ProductDesignGuard($this->objectManager->get(ProductAuthorizationFactory::class)),
                new CmsPageDesignGuard($this->objectManager->get(PageAuthorizationFactory::class)),
            ],
            'followUps' => [],
        ]);
    }

    private function pageCall(): ApiCall
    {
        return new ApiCall(ApiCall::METHOD_POST, 'cmsPage', [], ['page' => [
            'identifier' => 'mago-integration-design-guard',
            'title' => 'Design guard',
            'Page_Layout' => self::PAGE_LAYOUT,
        ]], self::ADMIN_USER_ID, null);
    }

    private function productCall(): ApiCall
    {
        return new ApiCall(ApiCall::METHOD_POST, 'products', [], ['product' => [
            'sku' => 'mago-integration-design-guard',
            'name' => 'Design guard',
            'attribute_set_id' => self::DEFAULT_ATTRIBUTE_SET_ID,
            'type_id' => 'simple',
            'price' => 1,
            'custom_attributes' => ['page_layout' => '1column'],
        ]], self::ADMIN_USER_ID, null);
    }
}

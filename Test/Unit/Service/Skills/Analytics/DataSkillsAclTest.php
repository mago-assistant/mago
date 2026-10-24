<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Analytics;

use MagoAssistant\Mago\Service\Skills\Analytics\CustomerData;
use MagoAssistant\Mago\Service\Skills\Analytics\ProductData;
use MagoAssistant\Mago\Service\Skills\Content\CmsData;
use MagoAssistant\Mago\Service\Url\AdminRouteAcl;
use MagoAssistant\Mago\Service\Url\EntityRouteMap;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAction;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAuthorization;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #148: the customer, product and CMS skills declared no resource at either layer, so any
 * admin holding the chat grant could read the customer register, the catalog and CMS content, and
 * write CMS content, whatever their Magento role denied. Each now asks for the resource Magento
 * guards the matching admin screen with; which resource that is, is Magento's answer and is
 * stubbed here (checked against the real route config by mago:tool:verify).
 */
final class DataSkillsAclTest extends TestCase
{
    private const GRID = [
        'customer/index/index' => 'Magento_Customer::manage',
        'catalog/product/index' => 'Magento_Catalog::products',
        'cms/page/index' => 'Magento_Cms::page',
        'cms/block/index' => 'Magento_Cms::block',
    ];

    private const EDIT = [
        'cms/page/edit' => 'Magento_Cms::save',
        'cms/block/edit' => 'Magento_Cms::block',
    ];

    private function routeMap(): EntityRouteMap
    {
        $adminRouteAcl = $this->createMock(AdminRouteAcl::class);
        $adminRouteAcl->method('forRoute')->willReturnCallback(
            static fn(string $route): ?string => (self::GRID + self::EDIT)[$route] ?? null
        );

        return new EntityRouteMap($adminRouteAcl);
    }

    #[Test]
    public function theCustomerRegisterIsGatedByTheCustomersGrid(): void
    {
        $skill = new CustomerData(new FakeAuthorization(), $this->routeMap());

        foreach (['count', 'lookup_customer', 'recent_signups'] as $action) {
            self::assertSame('Magento_Customer::manage', $skill->getMagentoAcl(['action' => $action]), $action);
        }
    }

    #[Test]
    public function theCatalogIsGatedByTheProductsGrid(): void
    {
        $skill = new ProductData(new FakeAuthorization(), $this->routeMap());

        foreach (['count', 'get_by_sku', 'search'] as $action) {
            self::assertSame('Magento_Catalog::products', $skill->getMagentoAcl(['action' => $action]), $action);
        }
    }

    /**
     * A CMS read takes the grid's resource; a CMS write takes the edit screen's, which for pages is
     * Magento_Cms::save - the same resource the REST route the write goes through requires, and not
     * the one that merely sounds right (the issue's case 2).
     */
    #[Test]
    public function cmsReadsTakeTheGridAndWritesTheEditScreen(): void
    {
        $skill = new CmsData(new FakeAuthorization(), $this->routeMap(), [
            'get_page' => new FakeAction('get_page', true),
            'list_pages' => new FakeAction('list_pages', true),
            'create_page' => new FakeAction('create_page', false),
            'update_page' => new FakeAction('update_page', false),
            'get_block' => new FakeAction('get_block', true),
            'list_blocks' => new FakeAction('list_blocks', true),
            'create_block' => new FakeAction('create_block', false),
            'update_block' => new FakeAction('update_block', false),
        ]);

        self::assertSame('Magento_Cms::page', $skill->getMagentoAcl(['action' => 'get_page']));
        self::assertSame('Magento_Cms::page', $skill->getMagentoAcl(['action' => 'list_pages']));
        self::assertSame('Magento_Cms::save', $skill->getMagentoAcl(['action' => 'create_page']));
        self::assertSame('Magento_Cms::save', $skill->getMagentoAcl(['action' => 'update_page']));
        self::assertSame('Magento_Cms::block', $skill->getMagentoAcl(['action' => 'get_block']));
        self::assertSame('Magento_Cms::block', $skill->getMagentoAcl(['action' => 'create_block']));
    }

    /**
     * Empty or unknown input fails closed to the strictest resource this skill reaches - the page
     * edit screen - rather than guessing from the action's name; the map is the declaration.
     */
    #[Test]
    public function emptyOrUnknownCmsInputFailsClosedToThePageEditScreen(): void
    {
        $skill = new CmsData(new FakeAuthorization(), $this->routeMap(), [
            'publish_page' => new FakeAction('publish_page', false),
        ]);

        self::assertSame('Magento_Cms::save', $skill->getMagentoAcl());
        self::assertSame('Magento_Cms::save', $skill->getMagentoAcl(['action' => 'publish_page']));
    }

    /**
     * A route Magento resolves no controller for leaves nothing to declare, and an empty
     * declaration is refused - never silently open.
     */
    #[Test]
    public function anUnresolvableRouteDeclaresNothing(): void
    {
        $adminRouteAcl = $this->createMock(AdminRouteAcl::class);
        $adminRouteAcl->method('forRoute')->willReturn(null);

        $skill = new CustomerData(new FakeAuthorization(), new EntityRouteMap($adminRouteAcl));

        self::assertSame('', $skill->getMagentoAcl(['action' => 'count']));
    }
}

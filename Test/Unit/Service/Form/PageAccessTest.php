<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Form;

use MagoAssistant\Mago\Model\Form\PageContext;
use MagoAssistant\Mago\Service\Form\PageAccess;
use MagoAssistant\Mago\Service\Url\AdminPath;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAclAuthorization;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAdminRouteAcl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeBackendUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PageAccessTest extends TestCase
{
    private const PRODUCT_EDIT = 'catalog/product/edit';
    private const PRODUCTS = 'Magento_Catalog::products';

    #[Test]
    public function itAllowsAnOpenPageWhoseResourceTheAdminHolds(): void
    {
        $pageAccess = $this->pageAccess([self::PRODUCTS]);

        $denial = $pageAccess->findOpenPageDenial($this->pageAt('/admin/catalog/product/edit/id/5/key/abc/'));

        self::assertNull($denial);
    }

    #[Test]
    public function itDeniesAnOpenPageWhoseResourceTheAdminLacksAndNamesTheResource(): void
    {
        $pageAccess = $this->pageAccess([]);

        $denial = $pageAccess->findOpenPageDenial($this->pageAt('/admin/catalog/product/edit/id/5/'));

        self::assertStringStartsWith('Access denied', (string)$denial);
        self::assertStringContainsString(self::PRODUCTS, (string)$denial);
    }

    #[Test]
    public function itResolvesTheRouteFromTheFirstThreeSegmentsBelowTheAdminPath(): void
    {
        $routeAcl = new FakeAdminRouteAcl([self::PRODUCT_EDIT => self::PRODUCTS]);
        $pageAccess = $this->pageAccess([self::PRODUCTS], $routeAcl);

        $pageAccess->findOpenPageDenial($this->pageAt('/admin/catalog/product/edit/id/5/key/abc/'));

        self::assertSame([self::PRODUCT_EDIT], $routeAcl->askedRoutes());
    }

    #[Test]
    public function itStripsACustomAdminPathInASubdirectory(): void
    {
        $pageAccess = new PageAccess(
            new AdminPath(new FakeBackendUrl('https://shop.test/magento/index.php/', 'backoffice')),
            new FakeAdminRouteAcl([self::PRODUCT_EDIT => self::PRODUCTS]),
            new FakeAclAuthorization([self::PRODUCTS])
        );

        $denial = $pageAccess->findOpenPageDenial(
            $this->pageAt('/magento/index.php/backoffice/catalog/product/edit/id/5/')
        );

        self::assertNull($denial);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unresolvablePaths(): array
    {
        return [
            'no controller answers the route' => ['/admin/vendor/thing/edit/id/5/'],
            'outside the admin path' => ['/catalog/product/edit/id/5/'],
            'another admin front name' => ['/backoffice/catalog/product/edit/id/5/'],
            'the admin path alone' => ['/admin/'],
            'not a path at all' => ['catalog/product/edit'],
        ];
    }

    #[Test]
    #[DataProvider('unresolvablePaths')]
    public function itDeniesAnOpenPageItCannotResolveEvenForAnAdminWhoHoldsEverything(string $path): void
    {
        $pageAccess = $this->pageAccess([self::PRODUCTS, 'Magento_Backend::admin']);

        $denial = $pageAccess->findOpenPageDenial($this->pageAt($path));

        self::assertStringContainsString('cannot tell which Magento permission', (string)$denial);
    }

    #[Test]
    public function itAllowsARouteWhoseResourceTheAdminHolds(): void
    {
        self::assertNull($this->pageAccess([self::PRODUCTS])->findRouteDenial(self::PRODUCT_EDIT));
    }

    #[Test]
    public function itDeniesARouteWhoseResourceTheAdminLacks(): void
    {
        $denial = $this->pageAccess([])->findRouteDenial(self::PRODUCT_EDIT);

        self::assertStringContainsString(self::PRODUCTS, (string)$denial);
    }

    #[Test]
    public function itDeniesARouteNoControllerAnswers(): void
    {
        $denial = $this->pageAccess([self::PRODUCTS])->findRouteDenial('vendor/thing/edit');

        self::assertStringContainsString('cannot tell which Magento permission', (string)$denial);
    }

    /**
     * @param string[] $allowedResources
     */
    private function pageAccess(array $allowedResources, ?FakeAdminRouteAcl $routeAcl = null): PageAccess
    {
        return new PageAccess(
            new AdminPath(new FakeBackendUrl()),
            $routeAcl ?? new FakeAdminRouteAcl([self::PRODUCT_EDIT => self::PRODUCTS]),
            new FakeAclAuthorization($allowedResources)
        );
    }

    private function pageAt(string $path): PageContext
    {
        return new PageContext(
            route: $path,
            namespace: 'product_form',
            entityType: 'product',
            entityId: '5',
            isNewEntity: false,
            storeId: null,
            fields: [],
            fieldCount: 0
        );
    }
}

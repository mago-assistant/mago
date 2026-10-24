<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Api\InProcess\Guard;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use MagoAssistant\Mago\Service\Api\InProcess\Guard\DesignChangeRefusedException;
use MagoAssistant\Mago\Service\Api\InProcess\Guard\ProductDesignGuard;
use MagoAssistant\Mago\Service\Api\InProcess\ResolvedRoute;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAclAuthorization;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeProductAuthorizationFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProductDesignGuardTest extends TestCase
{
    private const PRODUCT_REPOSITORY = 'Magento\Catalog\Api\ProductRepositoryInterface';

    #[Test]
    public function itRefusesADesignChangeWithCoresMessageAndTheMissingPermission(): void
    {
        $guard = new ProductDesignGuard(new FakeProductAuthorizationFactory(true));

        $this->expectException(DesignChangeRefusedException::class);
        $this->expectExceptionMessage(
            'Not allowed to edit the product\'s design attributes. '
            . 'This needs the Magento_Catalog::edit_product_design permission.'
        );

        $guard->guard($this->productSave(), [$this->product()], new FakeAclAuthorization(['Magento_Catalog::products']));
    }

    #[Test]
    public function itLetsAnAdminWithTheEditProductDesignPermissionChangeTheDesign(): void
    {
        $factory = new FakeProductAuthorizationFactory(true);
        $product = $this->product();

        (new ProductDesignGuard($factory))->guard(
            $this->productSave(),
            [$product],
            new FakeAclAuthorization([ProductDesignGuard::ACL_RESOURCE])
        );

        self::assertSame([$product], $factory->createdAuthorizations()[0]->checkedProducts());
    }

    #[Test]
    public function itChecksTheConvertedProductAsTheAdminTheCallActsFor(): void
    {
        $factory = new FakeProductAuthorizationFactory(false);
        $product = $this->product();
        $adminAuthorization = new FakeAclAuthorization([]);

        (new ProductDesignGuard($factory))->guard($this->productSave(), [$product, true], $adminAuthorization);

        $created = $factory->createdAuthorizations();
        self::assertCount(1, $created);
        self::assertSame($adminAuthorization, $created[0]->adminAuthorization());
        self::assertSame([$product], $created[0]->checkedProducts());
    }

    #[Test]
    public function itGuardsARouteDeclaredOnTheConcreteRepository(): void
    {
        $factory = new FakeProductAuthorizationFactory(true);
        $route = new ResolvedRoute('Magento\Catalog\Model\ProductRepository', 'save', '/V1/x/products', [], []);

        $this->expectException(DesignChangeRefusedException::class);

        (new ProductDesignGuard($factory))->guard($route, [$this->product()], new FakeAclAuthorization([]));
    }

    #[Test]
    public function itLeavesOtherRoutesOfTheProductRepositoryAlone(): void
    {
        $factory = new FakeProductAuthorizationFactory(true);
        $route = new ResolvedRoute(self::PRODUCT_REPOSITORY, 'get', '/V1/products/:sku', [], ['sku' => 'mug']);

        (new ProductDesignGuard($factory))->guard($route, ['mug'], new FakeAclAuthorization([]));

        self::assertSame([], $factory->createdAuthorizations());
    }

    #[Test]
    public function itLeavesASaveOfAnotherRepositoryAlone(): void
    {
        $factory = new FakeProductAuthorizationFactory(true);
        $route = new ResolvedRoute('Magento\Cms\Api\PageRepositoryInterface', 'save', '/V1/cmsPage', [], []);

        (new ProductDesignGuard($factory))->guard($route, [$this->product()], new FakeAclAuthorization([]));

        self::assertSame([], $factory->createdAuthorizations());
    }

    private function productSave(): ResolvedRoute
    {
        return new ResolvedRoute(self::PRODUCT_REPOSITORY, 'save', '/V1/products', ['Magento_Catalog::products'], []);
    }

    private function product(): ProductInterface
    {
        return (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
    }
}

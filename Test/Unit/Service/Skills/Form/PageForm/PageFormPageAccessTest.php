<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Form\PageForm;

use MagoAssistant\Mago\Model\Form\PageContext;
use MagoAssistant\Mago\Service\Form\FormPolicy;
use MagoAssistant\Mago\Service\Form\PageAccess;
use MagoAssistant\Mago\Service\Form\PageContextHolder;
use MagoAssistant\Mago\Service\Skills\Form\PageForm\DescribeFormAction;
use MagoAssistant\Mago\Service\Skills\Form\PageForm\ReadFieldsAction;
use MagoAssistant\Mago\Service\Skills\Form\PageForm\WriteFieldsAction;
use MagoAssistant\Mago\Service\Url\AdminPath;
use MagoAssistant\Mago\Service\Url\EntityRouteMap;
use MagoAssistant\Mago\Service\Url\NewEntityUrlBuilder;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAclAuthorization;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAdminRouteAcl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeBackendUrl;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #203: the form a page_form action works on is reported by the browser, so the server checks the
 * Magento ACL resource guarding that page itself instead of assuming the open form proves it.
 */
final class PageFormPageAccessTest extends TestCase
{
    private const PRODUCTS = 'Magento_Catalog::products';
    private const CMS_SAVE = 'Magento_Cms::save';
    private const PRODUCT_PAGE = '/admin/catalog/product/edit/id/5/key/abc/';
    private const UNKNOWN_PAGE = '/admin/vendor/thing/edit/id/5/';
    private const ROUTE_RESOURCES = [
        'catalog/product/edit' => self::PRODUCTS,
        'catalog/product/new' => self::PRODUCTS,
        'cms/page/edit' => self::CMS_SAVE,
    ];

    #[Test]
    public function describeFormListsTheFieldsOfAPageTheAdminMayOpen(): void
    {
        $result = $this->describe(self::PRODUCT_PAGE, [self::PRODUCTS])->execute([], 1);

        self::assertSame('product_form', $result['namespace']);
        self::assertSame('data.product.name', $result['fields'][0]['path']);
    }

    #[Test]
    public function describeFormRefusesAPageTheAdminsRoleMayNotOpen(): void
    {
        $result = $this->describe(self::PRODUCT_PAGE, [])->execute([], 1);

        $this->assertAccessRefusal($result, self::PRODUCTS);
    }

    #[Test]
    public function readFieldsReturnsValuesOfAPageTheAdminMayOpen(): void
    {
        $action = $this->read(self::PRODUCT_PAGE, [self::PRODUCTS]);

        $result = $action->execute(['field_paths' => ['data.product.name']], 1);

        self::assertSame('Blue shirt', $result['fields'][0]['value']);
    }

    #[Test]
    public function readFieldsRefusesAPageTheAdminsRoleMayNotOpen(): void
    {
        $result = $this->read(self::PRODUCT_PAGE, [])->execute(['field_paths' => ['data.product.name']], 1);

        $this->assertAccessRefusal($result, self::PRODUCTS);
    }

    #[Test]
    public function writeFieldsStagesOnAPageTheAdminMayOpen(): void
    {
        $action = $this->write($this->productForm(self::PRODUCT_PAGE), [self::PRODUCTS]);

        $result = $action->execute($this->openFormWrite(), 1);

        self::assertTrue($result['staged']);
    }

    #[Test]
    public function writeFieldsRefusesAPageTheAdminsRoleMayNotOpenBeforeConfirmation(): void
    {
        $result = $this->write($this->productForm(self::PRODUCT_PAGE), [])->findRefusal($this->openFormWrite());

        $this->assertAccessRefusal((array)$result, self::PRODUCTS);
    }

    #[Test]
    public function writeFieldsRefusesAPageTheAdminsRoleMayNotOpenOnTheConfirmRequest(): void
    {
        $result = $this->write($this->productForm(self::PRODUCT_PAGE), [])->execute($this->openFormWrite(), 1);

        $this->assertAccessRefusal($result, self::PRODUCTS);
        self::assertArrayNotHasKey('client_directive', $result);
    }

    #[Test]
    public function everyActionRefusesAPageWhoseRouteCannotBeResolved(): void
    {
        $allowed = [self::PRODUCTS, 'Magento_Backend::admin'];

        $results = [
            $this->describe(self::UNKNOWN_PAGE, $allowed)->execute([], 1),
            $this->read(self::UNKNOWN_PAGE, $allowed)->execute(['field_paths' => ['data.product.name']], 1),
            (array)$this->write($this->productForm(self::UNKNOWN_PAGE), $allowed)->findRefusal($this->openFormWrite()),
            $this->write($this->productForm(self::UNKNOWN_PAGE), $allowed)->execute($this->openFormWrite(), 1),
        ];

        foreach ($results as $result) {
            self::assertTrue($result['denied'] ?? false);
            self::assertStringContainsString('cannot tell which Magento permission', $result['message']);
        }
    }

    #[Test]
    public function writeFieldsRefusesToNavigateToAPageTheAdminsRoleMayNotOpen(): void
    {
        $result = $this->write(null, [self::PRODUCTS])->findRefusal([
            'entity_type' => 'cms_page',
            'entity_id' => '3',
            'changes' => [['path' => 'data.title', 'value' => 'Home']],
        ]);

        $this->assertAccessRefusal((array)$result, self::CMS_SAVE);
    }

    #[Test]
    public function writeFieldsStillNavigatesToAPageTheAdminMayOpen(): void
    {
        $result = $this->write(null, [self::PRODUCTS])->findRefusal([
            'entity_type' => 'product',
            'entity_id' => '',
            'changes' => [['path' => 'data.product.name', 'value' => 'Blue shirt']],
        ]);

        self::assertNull($result);
    }

    /**
     * @param array<string,mixed> $result
     */
    private function assertAccessRefusal(array $result, string $resource): void
    {
        self::assertTrue($result['denied'] ?? false);
        self::assertStringStartsWith('Access denied', $result['message']);
        self::assertStringContainsString($resource, $result['message']);
        self::assertArrayNotHasKey('fields', $result);
    }

    /**
     * @return array<string,mixed>
     */
    private function openFormWrite(): array
    {
        return [
            'form_namespace' => 'product_form',
            'entity_id' => '5',
            'store_id' => '',
            'changes' => [['path' => 'data.product.name', 'value' => 'Red shirt']],
        ];
    }

    /**
     * @param string[] $allowedResources
     */
    private function describe(string $path, array $allowedResources): DescribeFormAction
    {
        return new DescribeFormAction($this->holder($this->productForm($path)), $this->pageAccess($allowedResources));
    }

    /**
     * @param string[] $allowedResources
     */
    private function read(string $path, array $allowedResources): ReadFieldsAction
    {
        return new ReadFieldsAction($this->holder($this->productForm($path)), $this->pageAccess($allowedResources));
    }

    /**
     * NewEntityUrlBuilder needs EAV config and is never reached by these calls, so it is built
     * without its constructor rather than with a database behind it.
     *
     * @param string[] $allowedResources
     */
    private function write(?PageContext $pageContext, array $allowedResources): WriteFieldsAction
    {
        $routeAcl = new FakeAdminRouteAcl(self::ROUTE_RESOURCES);

        return new WriteFieldsAction(
            $this->holder($pageContext),
            new EntityRouteMap($routeAcl),
            new SecureAdminUrl(new FakeBackendUrl()),
            (new \ReflectionClass(NewEntityUrlBuilder::class))->newInstanceWithoutConstructor(),
            new FormPolicy(),
            $this->pageAccess($allowedResources)
        );
    }

    /**
     * @param string[] $allowedResources
     */
    private function pageAccess(array $allowedResources): PageAccess
    {
        return new PageAccess(
            new AdminPath(new FakeBackendUrl()),
            new FakeAdminRouteAcl(self::ROUTE_RESOURCES),
            new FakeAclAuthorization($allowedResources)
        );
    }

    private function holder(?PageContext $pageContext): PageContextHolder
    {
        $holder = new PageContextHolder();
        $holder->set($pageContext);

        return $holder;
    }

    private function productForm(string $path): PageContext
    {
        return new PageContext(
            route: $path,
            namespace: 'product_form',
            entityType: 'product',
            entityId: '5',
            isNewEntity: false,
            storeId: null,
            fields: [[
                'path' => 'data.product.name',
                'label' => 'Product Name',
                'type' => 'text',
                'value' => 'Blue shirt',
                'options' => null,
                'required' => true,
                'disabled' => false,
                'usesDefaultValue' => false,
            ]],
            fieldCount: 1
        );
    }
}

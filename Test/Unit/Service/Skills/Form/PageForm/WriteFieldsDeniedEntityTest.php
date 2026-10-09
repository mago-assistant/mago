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
use MagoAssistant\Mago\Service\Skills\Form\PageForm\WriteFieldsAction;
use MagoAssistant\Mago\Service\Url\AdminPath;
use MagoAssistant\Mago\Service\Url\AdminRouteAcl;
use MagoAssistant\Mago\Service\Url\EntityRouteMap;
use MagoAssistant\Mago\Service\Url\NewEntityUrlBuilder;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAclAuthorization;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAdminRouteAcl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeBackendUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WriteFieldsDeniedEntityTest extends TestCase
{
    private const CHANGES = [['path' => 'data.customer.firstname', 'value' => 'Jan']];

    /**
     * @return array<string, array{string, string}>
     */
    public static function deniedTargets(): array
    {
        return [
            'new customer' => ['customer', ''],
            'existing customer' => ['customer', '12'],
            'existing order' => ['order', '5'],
            'existing invoice' => ['invoice', '3'],
            'existing shipment' => ['shipment', '4'],
            'existing credit memo' => ['creditmemo', '6'],
            'new invoice' => ['invoice', ''],
            'customer address' => ['customer_address', ''],
            'admin user' => ['admin_user', '3'],
            'capitalised' => ['Customer', ''],
        ];
    }

    #[Test]
    #[DataProvider('deniedTargets')]
    public function itGivesThePrivacyRefusalWithNoFormOpen(string $entityType, string $entityId): void
    {
        $result = $this->action(null)->findRefusal($this->params($entityType, $entityId));

        self::assertTrue($result['denied'] ?? false);
        self::assertStringContainsString('personal data', $result['message']);
    }

    #[Test]
    #[DataProvider('deniedTargets')]
    public function itGivesThePrivacyRefusalWithAnotherFormOpen(string $entityType, string $entityId): void
    {
        $result = $this->action($this->productForm())->findRefusal($this->params($entityType, $entityId));

        self::assertTrue($result['denied'] ?? false);
    }

    #[Test]
    public function itStillNavigatesToANewProductForm(): void
    {
        self::assertNull($this->action(null)->findRefusal($this->params('product', '')));
        self::assertNull($this->action(null)->findRefusal($this->params('Product', '')));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unknownEntityTypes(): array
    {
        return [
            'none' => [''],
            'unknown' => ['customers'],
        ];
    }

    #[Test]
    #[DataProvider('unknownEntityTypes')]
    public function itStillSaysNoFormIsOpenForAnUnknownEntityType(string $entityType): void
    {
        $result = $this->action(null)->findRefusal($this->params($entityType, ''));

        self::assertFalse($result['form_open']);
        self::assertArrayNotHasKey('denied', $result);
    }

    /**
     * @return array<string, mixed>
     */
    private function params(string $entityType, string $entityId): array
    {
        return ['entity_type' => $entityType, 'entity_id' => $entityId, 'changes' => self::CHANGES];
    }

    private function action(?PageContext $pageContext): WriteFieldsAction
    {
        $holder = new PageContextHolder();
        $holder->set($pageContext);

        return new WriteFieldsAction(
            $holder,
            new EntityRouteMap($this->createStub(AdminRouteAcl::class)),
            $this->createStub(SecureAdminUrl::class),
            $this->createStub(NewEntityUrlBuilder::class),
            new FormPolicy(),
            new PageAccess(
                new AdminPath(new FakeBackendUrl()),
                new FakeAdminRouteAcl(['catalog/product/new' => 'Magento_Catalog::products']),
                new FakeAclAuthorization(['Magento_Catalog::products'])
            )
        );
    }

    private function productForm(): PageContext
    {
        return new PageContext(
            route: '/admin/catalog/product/edit/id/1/',
            namespace: 'product_form',
            entityType: 'product',
            entityId: '1',
            isNewEntity: false,
            storeId: null,
            fields: [],
            fieldCount: 0,
            isFieldListTruncated: false
        );
    }
}

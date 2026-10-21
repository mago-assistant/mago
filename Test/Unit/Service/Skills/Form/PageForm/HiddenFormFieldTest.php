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
 * A value staged into a field the administrator cannot see (the CMS page form's page_id, a store id
 * carried along) is saved with their own Save click without them ever seeing it.
 */
final class HiddenFormFieldTest extends TestCase
{
    private const CMS_SAVE = 'Magento_Cms::save';
    private const PAGE = '/admin/cms/page/edit/page_id/3/key/abc/';
    private const ROUTE_RESOURCES = ['cms/page/edit' => self::CMS_SAVE];

    #[Test]
    public function writeFieldsRefusesAHiddenFieldBeforeTheConfirmationCard(): void
    {
        $refusal = $this->writeFields()->findRefusal($this->writeTo('page_id', '9'));

        self::assertStringContainsString('"page_id" is a hidden field on this form', (string)($refusal['error'] ?? ''));
    }

    #[Test]
    public function writeFieldsNeverStagesAHiddenFieldOnTheConfirmRequest(): void
    {
        $result = $this->writeFields()->execute($this->writeTo('page_id', '9'), 1);

        self::assertArrayNotHasKey('client_directive', $result);
        self::assertStringContainsString('hidden field', (string)($result['error'] ?? ''));
    }

    #[Test]
    public function writeFieldsStillStagesAFieldTheAdministratorCanSee(): void
    {
        $result = $this->writeFields()->execute($this->writeTo('title', 'Home'), 1);

        self::assertTrue($result['staged']);
    }

    #[Test]
    public function describeFormDoesNotOfferAHiddenField(): void
    {
        $result = (new DescribeFormAction($this->holder(), $this->pageAccess()))->execute([], 1);

        self::assertSame(['title'], array_column($result['fields'], 'path'));
    }

    /**
     * @return array<string, mixed>
     */
    private function writeTo(string $path, string $value): array
    {
        return [
            'form_namespace' => 'cms_page_form',
            'entity_id' => '3',
            'store_id' => '',
            'changes' => [['path' => $path, 'value' => $value]],
        ];
    }

    private function writeFields(): WriteFieldsAction
    {
        return new WriteFieldsAction(
            $this->holder(),
            new EntityRouteMap(new FakeAdminRouteAcl(self::ROUTE_RESOURCES)),
            new SecureAdminUrl(new FakeBackendUrl()),
            (new \ReflectionClass(NewEntityUrlBuilder::class))->newInstanceWithoutConstructor(),
            new FormPolicy(),
            $this->pageAccess()
        );
    }

    private function pageAccess(): PageAccess
    {
        return new PageAccess(
            new AdminPath(new FakeBackendUrl()),
            new FakeAdminRouteAcl(self::ROUTE_RESOURCES),
            new FakeAclAuthorization([self::CMS_SAVE])
        );
    }

    private function holder(): PageContextHolder
    {
        $holder = new PageContextHolder();
        $holder->set(new PageContext(
            route: self::PAGE,
            namespace: 'cms_page_form',
            entityType: 'cms_page',
            entityId: '3',
            isNewEntity: false,
            storeId: null,
            fields: [$this->field('title', 'Page Title', false), $this->field('page_id', '', true)],
            fieldCount: 2
        ));

        return $holder;
    }

    /**
     * @return array<string, mixed>
     */
    private function field(string $path, string $label, bool $isHidden): array
    {
        return [
            'path' => $path,
            'label' => $label,
            'type' => 'input',
            'value' => '3',
            'options' => null,
            'required' => false,
            'disabled' => false,
            'hidden' => $isHidden,
            'usesDefaultValue' => false,
        ];
    }
}

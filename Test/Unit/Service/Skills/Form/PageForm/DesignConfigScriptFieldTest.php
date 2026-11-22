<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Form\PageForm;

use MagoAssistant\Mago\Model\Form\PageContext;
use MagoAssistant\Mago\Service\Form\BlockedFormFields;
use MagoAssistant\Mago\Service\Form\FormPolicy;
use MagoAssistant\Mago\Service\Form\PageAccess;
use MagoAssistant\Mago\Service\Form\PageContextHolder;
use MagoAssistant\Mago\Service\Skills\Configuration\BlockedConfigPaths;
use MagoAssistant\Mago\Service\Skills\Form\PageForm\WriteFieldsAction;
use MagoAssistant\Mago\Service\Url\AdminPath;
use MagoAssistant\Mago\Service\Url\EntityRouteMap;
use MagoAssistant\Mago\Service\Url\NewEntityUrlBuilder;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAclAuthorization;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAdminRouteAcl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeBackendUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeDesignConfigMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Security audit follow-up 2026-11-02, finding 3: config_writer never changes the scripts every
 * storefront page loads (BlockedConfigPaths), but Content > Design > Configuration shows the same
 * two settings as fields of a UI component form, where page_form.write_fields stages them.
 */
final class DesignConfigScriptFieldTest extends TestCase
{
    private const BACKEND_ADMIN = 'Magento_Backend::admin';
    private const PAGE = '/admin/theme/design_config/edit/scope/default/key/abc/';
    private const ROUTE_RESOURCES = ['theme/design_config/edit' => self::BACKEND_ADMIN];
    private const NAMESPACE = 'design_config_form';
    private const SCRIPT = '<script src="https://example.test/marker.js"></script>';

    /**
     * @return array<string, array{string, string}>
     */
    public static function scriptFields(): array
    {
        return [
            'scripts and style sheets in the head' => ['data.head_includes', 'design/head/includes'],
            'miscellaneous HTML in the footer' => ['data.footer_absolute_footer', 'design/footer/absolute_footer'],
        ];
    }

    #[Test]
    #[DataProvider('scriptFields')]
    public function writeFieldsRefusesAFieldWhoseSettingConfigWriterBlocksBeforeTheConfirmationCard(
        string $fieldPath,
        string $configPath
    ): void {
        self::assertTrue((new BlockedConfigPaths())->isBlocked($configPath));

        $refusal = $this->writeFields()->findRefusal($this->writeTo($fieldPath, self::SCRIPT));

        self::assertNotNull(
            $refusal,
            sprintf(
                'config_writer blocks "%s", so write_fields must refuse "%s" on %s',
                $configPath,
                $fieldPath,
                self::NAMESPACE
            )
        );
    }

    #[Test]
    #[DataProvider('scriptFields')]
    public function writeFieldsNeverStagesAFieldWhoseSettingConfigWriterBlocksOnTheConfirmRequest(
        string $fieldPath,
        string $configPath
    ): void {
        $result = $this->writeFields()->execute($this->writeTo($fieldPath, self::SCRIPT), 1);

        self::assertArrayNotHasKey(
            'client_directive',
            $result,
            sprintf('config_writer blocks "%s", so "%s" must never reach the browser', $configPath, $fieldPath)
        );
    }

    #[Test]
    public function writeFieldsStillStagesADesignSettingConfigWriterAllows(): void
    {
        self::assertFalse((new BlockedConfigPaths())->isBlocked('design/head/default_title'));

        $result = $this->writeFields()->execute($this->writeTo('data.head_default_title', 'My store'), 1);

        self::assertTrue($result['staged'] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    private function writeTo(string $path, string $value): array
    {
        return [
            'form_namespace' => self::NAMESPACE,
            'entity_id' => '',
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
            new BlockedFormFields(new BlockedConfigPaths(), new FakeDesignConfigMetadata([
                'head_default_title' => 'design/head/default_title',
                'head_includes' => 'design/head/includes',
                'footer_absolute_footer' => 'design/footer/absolute_footer',
            ])),
            new PageAccess(
                new AdminPath(new FakeBackendUrl()),
                new FakeAdminRouteAcl(self::ROUTE_RESOURCES),
                new FakeAclAuthorization([self::BACKEND_ADMIN])
            )
        );
    }

    private function holder(): PageContextHolder
    {
        $holder = new PageContextHolder();
        $holder->set(new PageContext(
            route: self::PAGE,
            namespace: self::NAMESPACE,
            entityType: 'design_config',
            entityId: '',
            isNewEntity: true,
            storeId: null,
            fields: [
                $this->field('data.head_default_title', 'Default Page Title', 'input'),
                $this->field('data.head_includes', 'Scripts and Style Sheets', 'textarea'),
                $this->field('data.footer_absolute_footer', 'Miscellaneous HTML', 'textarea'),
            ],
            fieldCount: 3
        ));

        return $holder;
    }

    /**
     * @return array<string, mixed>
     */
    private function field(string $path, string $label, string $type): array
    {
        return [
            'path' => $path,
            'label' => $label,
            'type' => $type,
            'value' => '',
            'options' => null,
            'required' => false,
            'disabled' => false,
            'hidden' => false,
            'usesDefaultValue' => false,
        ];
    }
}

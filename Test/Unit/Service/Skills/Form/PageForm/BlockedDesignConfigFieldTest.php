<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Form\PageForm;

use MagoAssistant\Mago\Model\Form\PageContext;
use MagoAssistant\Mago\Service\Form\BlockedDesignConfigFields;
use MagoAssistant\Mago\Service\Form\FormPolicy;
use MagoAssistant\Mago\Service\Form\PageAccess;
use MagoAssistant\Mago\Service\Form\PageContextHolder;
use MagoAssistant\Mago\Service\Skills\Configuration\BlockedConfigPaths;
use MagoAssistant\Mago\Service\Skills\Form\PageForm\ReadFieldsAction;
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
 * The storefront script settings config_writer never changes (BlockedConfigPaths: design/head/includes
 * and design/footer/absolute_footer) are plain visible textareas on Content > Design > Configuration,
 * so page_form must refuse to stage them there too.
 */
final class BlockedDesignConfigFieldTest extends TestCase
{
    private const DESIGN_CONFIG = 'Magento_Config::config_design';
    private const PAGE = '/admin/theme/design_config/edit/scope/default/key/abc/';
    private const ROUTE_RESOURCES = ['theme/design_config/edit' => self::DESIGN_CONFIG];
    private const SCRIPT = '<script src="https://evil.example/s.js"></script>';

    /**
     * The field paths are the components' runtime dataScope: the form-level dataScope "data" plus
     * the field name, exactly as Magento's UI renderer composes them and form-bridge.js snapshots
     * them. A bare "head_includes" here would pass against a lookup the real form never hits.
     *
     * @return array<string, array{string, string}>
     */
    public static function blockedFields(): array
    {
        return [
            'head scripts' => ['data.head_includes', 'design/head/includes'],
            'footer scripts' => ['data.footer_absolute_footer', 'design/footer/absolute_footer'],
        ];
    }

    #[Test]
    #[DataProvider('blockedFields')]
    public function configWriterBlocksTheSettingBehindTheField(string $fieldPath, string $configPath): void
    {
        self::assertTrue((new BlockedConfigPaths())->isBlocked($configPath));
    }

    #[Test]
    #[DataProvider('blockedFields')]
    public function writeFieldsRefusesABlockedStorefrontScriptFieldBeforeTheConfirmationCard(
        string $fieldPath,
        string $configPath
    ): void {
        $refusal = $this->writeFields()->findRefusal($this->writeTo($fieldPath));

        self::assertNotNull(
            $refusal,
            'write_fields offered "' . $fieldPath . '" for confirmation, though config_writer blocks ' . $configPath
        );
    }

    #[Test]
    #[DataProvider('blockedFields')]
    public function writeFieldsNeverStagesABlockedStorefrontScriptField(string $fieldPath, string $configPath): void
    {
        $result = $this->writeFields()->execute($this->writeTo($fieldPath), 1);

        self::assertArrayNotHasKey(
            'client_directive',
            $result,
            'write_fields staged "' . $fieldPath . '", though config_writer blocks ' . $configPath
        );
    }

    #[Test]
    public function writeFieldsStillStagesADesignFieldThatIsNotBlocked(): void
    {
        $result = $this->writeFields()->execute($this->writeTo('data.footer_copyright'), 1);

        self::assertTrue($result['staged']);
    }

    /**
     * Reading stays allowed on purpose: "is there a skimmer in my head scripts?" is a legitimate
     * audit question. Only the write direction is refused.
     */
    #[Test]
    #[DataProvider('blockedFields')]
    public function readFieldsStillReturnsTheCurrentContentOfABlockedField(string $fieldPath, string $configPath): void
    {
        $result = (new ReadFieldsAction($this->holder(), $this->pageAccess()))
            ->execute(['field_paths' => [$fieldPath]], 1);

        self::assertTrue($result['fields'][0]['found']);
    }

    /**
     * @return array<string, mixed>
     */
    private function writeTo(string $path): array
    {
        return [
            'form_namespace' => 'design_config_form',
            'entity_id' => '',
            'store_id' => '',
            'changes' => [['path' => $path, 'value' => self::SCRIPT]],
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
            $this->pageAccess(),
            new BlockedDesignConfigFields(
                new FakeDesignConfigMetadata([
                    'head_includes' => 'design/head/includes',
                    'footer_absolute_footer' => 'design/footer/absolute_footer',
                    'footer_copyright' => 'design/footer/copyright',
                ]),
                new BlockedConfigPaths()
            )
        );
    }

    private function pageAccess(): PageAccess
    {
        return new PageAccess(
            new AdminPath(new FakeBackendUrl()),
            new FakeAdminRouteAcl(self::ROUTE_RESOURCES),
            new FakeAclAuthorization([self::DESIGN_CONFIG])
        );
    }

    private function holder(): PageContextHolder
    {
        $holder = new PageContextHolder();
        $holder->set(new PageContext(
            route: self::PAGE,
            namespace: 'design_config_form',
            entityType: '',
            entityId: '',
            isNewEntity: false,
            storeId: null,
            fields: [
                $this->field('data.head_includes', 'Scripts and Style Sheets'),
                $this->field('data.footer_absolute_footer', 'Miscellaneous HTML'),
                $this->field('data.footer_copyright', 'Copyright'),
            ],
            fieldCount: 3
        ));

        return $holder;
    }

    /**
     * @return array<string, mixed>
     */
    private function field(string $path, string $label): array
    {
        return [
            'path' => $path,
            'label' => $label,
            'type' => 'textarea',
            'value' => '',
            'options' => null,
            'required' => false,
            'disabled' => false,
            'hidden' => false,
            'usesDefaultValue' => false,
        ];
    }
}

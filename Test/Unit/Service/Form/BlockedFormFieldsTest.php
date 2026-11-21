<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Form;

use MagoAssistant\Mago\Service\Form\BlockedFormFields;
use MagoAssistant\Mago\Service\Skills\Configuration\BlockedConfigPaths;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeDesignConfigMetadata;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlockedFormFieldsTest extends TestCase
{
    private const DESIGN_CONFIG_FORM = 'design_config_form';

    #[Test]
    public function itBlocksTheDesignFieldsWhoseSettingsConfigWriterBlocks(): void
    {
        $blockedFormFields = $this->blockedFormFields();

        self::assertTrue($blockedFormFields->isBlocked(self::DESIGN_CONFIG_FORM, 'data.head_includes'));
        self::assertTrue($blockedFormFields->isBlocked(self::DESIGN_CONFIG_FORM, 'data.footer_absolute_footer'));
    }

    #[Test]
    public function itAllowsADesignFieldWhoseSettingConfigWriterAllows(): void
    {
        self::assertFalse($this->blockedFormFields()->isBlocked(self::DESIGN_CONFIG_FORM, 'data.head_default_title'));
    }

    #[Test]
    public function itOnlyBlocksFieldsOnTheDesignConfigurationForm(): void
    {
        self::assertFalse($this->blockedFormFields()->isBlocked('cms_page_form', 'data.head_includes'));
    }

    #[Test]
    public function itFollowsAPatternAddedThroughDiXml(): void
    {
        $blockedFormFields = new BlockedFormFields(
            new BlockedConfigPaths(['design/watermark/*']),
            new FakeDesignConfigMetadata(['watermark_image_imageOpacity' => 'design/watermark/image_imageOpacity'])
        );

        self::assertTrue($blockedFormFields->isBlocked(self::DESIGN_CONFIG_FORM, 'data.watermark_image_imageOpacity'));
    }

    #[Test]
    public function itPublishesTheBlockedFieldPathsKeyedByNamespace(): void
    {
        self::assertSame(
            [self::DESIGN_CONFIG_FORM => ['data.head_includes', 'data.footer_absolute_footer']],
            $this->blockedFormFields()->getBlockedFieldPaths()
        );
    }

    #[Test]
    public function itPublishesNothingWhenNoDesignFieldStoresABlockedSetting(): void
    {
        $blockedFormFields = new BlockedFormFields(
            new BlockedConfigPaths(),
            new FakeDesignConfigMetadata(['head_default_title' => 'design/head/default_title'])
        );

        self::assertSame([], $blockedFormFields->getBlockedFieldPaths());
    }

    private function blockedFormFields(): BlockedFormFields
    {
        return new BlockedFormFields(
            new BlockedConfigPaths(),
            new FakeDesignConfigMetadata([
                'head_default_title' => 'design/head/default_title',
                'head_includes' => 'design/head/includes',
                'footer_absolute_footer' => 'design/footer/absolute_footer',
            ])
        );
    }
}

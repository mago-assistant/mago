<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Model\Voice;

use MagoAssistant\Mago\Model\Voice\PanelConfig;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConfigRepository;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeLocaleResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PanelConfigTest extends TestCase
{
    #[Test]
    public function itOffersNoMicrophoneWhenVoiceInputIsOff(): void
    {
        $panelConfig = $this->panelConfig(false, 'nl_NL');

        self::assertSame(['voiceInput' => false], $panelConfig->getJsConfig());
    }

    #[Test]
    public function itListensInTheAdminUsersInterfaceLocale(): void
    {
        $panelConfig = $this->panelConfig(true, 'nl_NL');

        self::assertSame(['voiceInput' => true, 'voiceLocale' => 'nl-NL'], $panelConfig->getJsConfig());
    }

    #[Test]
    public function itKeepsALocaleWithAScriptAsOneLanguageTag(): void
    {
        $panelConfig = $this->panelConfig(true, 'zh_Hans_CN');

        self::assertSame('zh-Hans-CN', $panelConfig->getJsConfig()['voiceLocale']);
    }

    private function panelConfig(bool $isVoiceInputEnabled, string $locale): PanelConfig
    {
        return new PanelConfig(
            (new FakeConfigRepository())->withVoiceInput($isVoiceInputEnabled),
            new FakeLocaleResolver($locale)
        );
    }
}

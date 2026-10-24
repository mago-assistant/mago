<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Tool\Scaffold;

use Magento\Framework\Filesystem\Driver\File;
use MagoAssistant\Mago\Service\Tool\Scaffold\ToolScaffold;
use MagoAssistant\Mago\Service\Tool\Scaffold\ToolScaffolder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToolScaffolderTest extends TestCase
{
    #[Test]
    public function itRendersEveryFileOfTheModule(): void
    {
        $files = $this->render('read');

        self::assertSame(
            [
                'composer.json',
                'registration.php',
                'etc/module.xml',
                'etc/di.xml',
                'Service/Tool/VatCheck.php',
                'README.md',
            ],
            array_keys($files)
        );
    }

    #[Test]
    public function itLeavesNoPlaceholderBehind(): void
    {
        $leftovers = array_filter($this->render('read'), static fn (string $contents) => str_contains($contents, '{{'));

        self::assertSame([], array_keys($leftovers));
    }

    #[Test]
    public function theGeneratedPhpParses(): void
    {
        $files = $this->render('write');

        self::assertNotSame([], token_get_all($files['Service/Tool/VatCheck.php'], TOKEN_PARSE));
        self::assertNotSame([], token_get_all($files['registration.php'], TOKEN_PARSE));
    }

    #[Test]
    public function theToolClassCarriesTheAclAndAccessMode(): void
    {
        $readTool = $this->render('read')['Service/Tool/VatCheck.php'];
        $writeTool = $this->render('write')['Service/Tool/VatCheck.php'];

        self::assertStringContainsString("return 'Magento_Sales::actions_view';", $readTool);
        self::assertStringContainsString('namespace MagoAssistant\Vies\Service\Tool;', $readTool);
        self::assertStringContainsString('return true;', $readTool);
        self::assertStringNotContainsString('return true;', $writeTool);
        self::assertStringContainsString('return false;', $writeTool);
    }

    #[Test]
    public function theComposerJsonAutoloadsTheModuleNamespace(): void
    {
        $composer = json_decode($this->render('read')['composer.json'], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('mago-assistant/magento2-vies', $composer['name']);
        self::assertSame(['MagoAssistant\\Vies\\' => ''], $composer['autoload']['psr-4']);
    }

    #[Test]
    public function theDiXmlRegistersTheClassUnderTheToolName(): void
    {
        $diXml = $this->render('read')['etc/di.xml'];

        self::assertStringContainsString(
            '<item name="vies_vat_check" xsi:type="object">MagoAssistant\Vies\Service\Tool\VatCheck</item>',
            $diXml
        );
    }

    #[Test]
    public function itDeclaresAnAclResourceThatBelongsToTheNewModule(): void
    {
        $files = (new ToolScaffolder(new File()))->render(
            ToolScaffold::fromInput('vies_vat_check', 'MagoAssistant_Vies', 'MagoAssistant_Vies::check', 'read')
        );

        self::assertStringContainsString(
            '<resource id="MagoAssistant_Vies::check" title="vies_vat_check"/>',
            $files['etc/acl.xml']
        );
    }

    #[Test]
    public function itLeavesAnAclResourceOfAnotherModuleUndeclared(): void
    {
        self::assertArrayNotHasKey('etc/acl.xml', $this->render('read'));
    }

    /**
     * @return array<string,string>
     */
    private function render(string $access): array
    {
        return (new ToolScaffolder(new File()))->render(
            ToolScaffold::fromInput('vies_vat_check', 'MagoAssistant_Vies', 'Magento_Sales::actions_view', $access)
        );
    }
}

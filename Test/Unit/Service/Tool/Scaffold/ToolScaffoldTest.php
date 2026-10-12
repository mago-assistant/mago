<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Tool\Scaffold;

use MagoAssistant\Mago\Service\Tool\Scaffold\InvalidScaffoldException;
use MagoAssistant\Mago\Service\Tool\Scaffold\ToolAccess;
use MagoAssistant\Mago\Service\Tool\Scaffold\ToolScaffold;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToolScaffoldTest extends TestCase
{
    private const ACL = 'Magento_Sales::actions_view';

    #[Test]
    public function itDerivesTheClassAndPackageFromTheToolAndModule(): void
    {
        $scaffold = ToolScaffold::fromInput('vies_vat_check', 'MagoAssistant_Vies', self::ACL, 'read');

        self::assertSame('MagoAssistant', $scaffold->vendor);
        self::assertSame('Vies', $scaffold->module);
        self::assertSame('VatCheck', $scaffold->className);
        self::assertSame('mago-assistant/magento2-vies', $scaffold->packageName);
        self::assertSame(ToolAccess::Read, $scaffold->access);
        self::assertTrue($scaffold->isReadOnly());
    }

    #[Test]
    public function itKeepsTheWholeToolNameAsClassWhenItLacksTheModulePrefix(): void
    {
        $scaffold = ToolScaffold::fromInput('order_lookup', 'Acme_Vies', self::ACL, 'write');

        self::assertSame('OrderLookup', $scaffold->className);
        self::assertFalse($scaffold->isReadOnly());
    }

    #[Test]
    public function anExplicitClassAndPackageWin(): void
    {
        $scaffold = ToolScaffold::fromInput('vies_vat_check', 'Acme_Vies', self::ACL, 'read', 'Checker', 'acme/vies');

        self::assertSame('Checker', $scaffold->className);
        self::assertSame('acme/vies', $scaffold->packageName);
    }

    #[Test]
    public function itKnowsWhetherTheAclResourceBelongsToTheNewModule(): void
    {
        $own = ToolScaffold::fromInput('vies_check', 'Acme_Vies', 'Acme_Vies::check', 'read');
        $foreign = ToolScaffold::fromInput('vies_check', 'Acme_Vies', self::ACL, 'read');

        self::assertTrue($own->hasOwnAclResource());
        self::assertFalse($foreign->hasOwnAclResource());
    }

    /**
     * @return array<string,array{0:string,1:string,2:string,3:string,4?:string}>
     */
    public static function invalidInput(): array
    {
        return [
            'tool name with a dash' => ['vies-check', 'Acme_Vies', self::ACL, 'read'],
            'module without vendor' => ['vies_check', 'Vies', self::ACL, 'read'],
            'missing ACL' => ['vies_check', 'Acme_Vies', '', 'read'],
            'ACL without resource part' => ['vies_check', 'Acme_Vies', 'Magento_Sales', 'read'],
            'missing access' => ['vies_check', 'Acme_Vies', self::ACL, ''],
            'unknown access' => ['vies_check', 'Acme_Vies', self::ACL, 'readonly'],
            'lowercase class' => ['vies_check', 'Acme_Vies', self::ACL, 'read', 'checker'],
        ];
    }

    #[Test]
    #[DataProvider('invalidInput')]
    public function itRejectsInvalidInput(
        string $toolName,
        string $module,
        string $acl,
        string $access,
        string $className = ''
    ): void {
        $this->expectException(InvalidScaffoldException::class);

        ToolScaffold::fromInput($toolName, $module, $acl, $access, $className);
    }
}

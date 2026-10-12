<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Integration\Service\Skills\Configuration;

use Magento\Config\Model\Config\Structure;
use Magento\Framework\Config\ScopeInterface;
use MagoAssistant\Mago\Service\Skills\Configuration\ConfigPathAccess;
use MagoAssistant\Mago\Test\Integration\MagentoObjectManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Against the merged system.xml of a real install, read the way the REST chat reads it. Magento
 * only reads system.xml in the adminhtml area, which is how the unit tests' fake structure let an
 * empty structure under /V1/mago/chat go unnoticed (#215, #223).
 */
final class ConfigPathAccessTest extends TestCase
{
    private ?ScopeInterface $configScope = null;

    private string $previousScope = '';

    protected function setUp(): void
    {
        $objectManager = MagentoObjectManager::get();
        $this->configScope = $objectManager->get(ScopeInterface::class);
        $this->previousScope = (string)$this->configScope->getCurrentScope();
        $this->configScope->setCurrentScope('webapi_rest');

        // The plain structure's data is shared and keeps what it once loaded: warmed with the
        // adminhtml system.xml by an earlier test, it would answer here without the di.xml pin.
        // Loading it here, under webapi_rest, leaves it empty for later tests in this process.
        if ($objectManager->create(Structure::class)->getFieldPaths() !== []) {
            self::markTestSkipped('The plain config structure is already loaded in this process; run this test alone.');
        }
    }

    protected function tearDown(): void
    {
        $this->configScope?->setCurrentScope($this->previousScope);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function pathsAndTheirSection(): array
    {
        return [
            'own section' => ['web/secure/use_in_adminhtml', 'Magento_Config::web'],
            'admin settings' => ['admin/url/custom_path', 'Magento_Config::config_admin'],
            'config_path into another section' => ['paypal/general/merchant_country', 'Magento_Payment::payment'],
            'outside any section' => ['crontab/default/jobs', ''],
        ];
    }

    #[Test]
    #[DataProvider('pathsAndTheirSection')]
    public function itResolvesTheSectionOutsideTheAdminArea(string $path, string $resource): void
    {
        $access = MagentoObjectManager::get()->create(ConfigPathAccess::class);

        self::assertSame($resource, $access->aclResourceFor($path));
    }

    #[Test]
    public function itKnowsWhichPathsAFieldDeclaresOutsideTheAdminArea(): void
    {
        $access = MagentoObjectManager::get()->create(ConfigPathAccess::class);

        self::assertTrue($access->isDeclared('web/secure/use_in_adminhtml'));
        self::assertTrue($access->isDeclared('design/footer/copyright'));
        self::assertFalse($access->isDeclared('web/secure/made_up'));
    }
}

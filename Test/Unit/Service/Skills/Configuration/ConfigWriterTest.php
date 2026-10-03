<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Configuration;

use Magento\Config\Model\ResourceModel\Config as ConfigResource;
use Magento\Framework\App\Cache\TypeListInterface;
use MagoAssistant\Mago\Service\Skills\Configuration\ConfigPathAccess;
use MagoAssistant\Mago\Service\Skills\Configuration\ConfigWriter;
use MagoAssistant\Mago\Service\Store\StoreScopeContext;
use MagoAssistant\Mago\Test\Unit\Fakes\BuildsStoreLayouts;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConfigStructure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ConfigWriterTest extends TestCase
{
    use BuildsStoreLayouts;

    private const PATH = 'general/store_information/name';

    #[Test]
    public function itWritesToTheDefaultScopeWhenNoneIsGiven(): void
    {
        $resource = $this->createMock(ConfigResource::class);
        $resource->expects(self::once())->method('saveConfig')->with(self::PATH, 'Main Shop', 'default', 0);
        $cache = $this->createMock(TypeListInterface::class);
        $cache->expects(self::once())->method('cleanType')->with('config');

        $result = $this->writerWith($resource, $cache)->execute(['path' => self::PATH, 'value' => 'Main Shop']);

        self::assertTrue($result['success']);
        self::assertStringStartsWith('default scope', $result['scope_label']);
        self::assertStringContainsString('on default scope', $result['message']);
    }

    #[Test]
    public function itWritesToAStoreViewAndNamesItInTheMessage(): void
    {
        $resource = $this->createMock(ConfigResource::class);
        $resource->expects(self::once())->method('saveConfig')->with(self::PATH, 'Luma Shop', 'stores', 2);

        $result = $this->writerWith($resource)->execute([
            'path' => self::PATH,
            'value' => 'Luma Shop',
            'scope' => 'stores',
            'scope_id' => 2,
        ]);

        self::assertSame('store view "Luma" (id 2, code "luma")', $result['scope_label']);
        self::assertSame(
            'Configuration "general/store_information/name" has been set to "Luma Shop" on store view "Luma" (id 2, code "luma")',
            $result['message']
        );
    }

    #[Test]
    public function itRefusesToWriteToAnUnknownStoreView(): void
    {
        $resource = $this->createMock(ConfigResource::class);
        $resource->expects(self::never())->method('saveConfig');
        $cache = $this->createMock(TypeListInterface::class);
        $cache->expects(self::never())->method('cleanType');

        $result = $this->writerWith($resource, $cache)->execute([
            'path' => self::PATH,
            'value' => 'x',
            'scope' => 'stores',
            'scope_id' => 9,
        ]);

        self::assertStringStartsWith('Unknown store view id 9', $result['error']);
    }

    #[Test]
    public function itRefusesADefaultScopeWithAScopeId(): void
    {
        $resource = $this->createMock(ConfigResource::class);
        $resource->expects(self::never())->method('saveConfig');

        $result = $this->writerWith($resource)->execute([
            'path' => self::PATH,
            'value' => 'x',
            'scope' => 'default',
            'scope_id' => 2,
        ]);

        self::assertStringContainsString('always uses scope_id 0', $result['error']);
    }

    #[Test]
    public function itStillBlocksSensitivePaths(): void
    {
        $resource = $this->createMock(ConfigResource::class);
        $resource->expects(self::never())->method('saveConfig');

        $result = $this->writerWith($resource)->execute(['path' => 'payment/checkmo/title', 'value' => 'Cheque']);

        self::assertStringContainsString('security reasons', $result['error']);
    }

    /**
     * Saving a section in the admin requires the resource that section declares in system.xml;
     * writing through chat asks for the same one, so the admin-security settings the blocklist
     * never named (admin path, session lifetime, HTTPS for the admin panel) are gated by their
     * section's resource rather than by the configuration area as a whole (#200).
     */
    #[Test]
    public function itGatesAPathByTheResourceOfItsSection(): void
    {
        $writer = $this->writerWith($this->createMock(ConfigResource::class));

        self::assertSame('Magento_Config::config_general', $writer->getMagentoAcl(['path' => self::PATH]));
        self::assertSame('Magento_Config::config_admin', $writer->getMagentoAcl(['path' => 'admin/url/custom_path']));
    }

    #[Test]
    public function itRefusesAPathOutsideAnyConfigurationSection(): void
    {
        $writer = $this->writerWith($this->createMock(ConfigResource::class));

        self::assertSame('', $writer->getMagentoAcl(['path' => 'crontab/default/jobs']));
    }

    #[Test]
    public function itAnswersTheConfigurationAreaWhenNoPathIsNamed(): void
    {
        $writer = $this->writerWith($this->createMock(ConfigResource::class));

        self::assertSame('Magento_Config::config', $writer->getMagentoAcl());
    }

    private function writerWith(ConfigResource $resource, ?TypeListInterface $cache = null): ConfigWriter
    {
        return new ConfigWriter(
            $resource,
            $cache ?? $this->createMock(TypeListInterface::class),
            new StoreScopeContext($this->multiStoreManager()),
            new ConfigPathAccess(
                (new FakeConfigStructure())
                    ->withSection('general', 'Magento_Config::config_general')
                    ->withSection('admin', 'Magento_Config::config_admin')
            )
        );
    }
}

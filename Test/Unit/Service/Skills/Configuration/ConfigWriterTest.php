<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Configuration;

use Magento\Config\Model\Config\TypePool;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\AuthorizationInterface;
use MagoAssistant\Mago\Service\Skills\Configuration\ConfigPathAccess;
use MagoAssistant\Mago\Service\Skills\Configuration\ConfigReader;
use MagoAssistant\Mago\Service\Skills\Configuration\ConfigValueSaver;
use MagoAssistant\Mago\Service\Skills\Configuration\ConfigWriteImpact;
use MagoAssistant\Mago\Service\Skills\Configuration\ConfigWriter;
use MagoAssistant\Mago\Service\Store\StoreScopeContext;
use MagoAssistant\Mago\Test\Unit\Fakes\BuildsStoreLayouts;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAclAuthorization;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAuthorization;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConfigStructure;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeDesignConfigMetadata;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeObjectManagerConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ConfigWriterTest extends TestCase
{
    use BuildsStoreLayouts;

    private const PATH = 'general/store_information/name';

    #[Test]
    public function itWritesToTheDefaultScopeWhenNoneIsGiven(): void
    {
        $resource = $this->createMock(ConfigValueSaver::class);
        $resource->expects(self::once())->method('save')->with(self::PATH, 'Main Shop', 'default', 0);
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
        $resource = $this->createMock(ConfigValueSaver::class);
        $resource->expects(self::once())->method('save')->with(self::PATH, 'Luma Shop', 'stores', 2);

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
        $resource = $this->createMock(ConfigValueSaver::class);
        $resource->expects(self::never())->method('save');
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
        $resource = $this->createMock(ConfigValueSaver::class);
        $resource->expects(self::never())->method('save');

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
        $resource = $this->createMock(ConfigValueSaver::class);
        $resource->expects(self::never())->method('save');

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
        $writer = $this->writerWith($this->createMock(ConfigValueSaver::class));

        self::assertSame('Magento_Config::config_general', $writer->getMagentoAcl(['path' => self::PATH]));
        self::assertSame('Magento_Config::config_admin', $writer->getMagentoAcl(['path' => 'admin/url/custom_path']));
    }

    #[Test]
    public function itRefusesAPathOutsideAnyConfigurationSection(): void
    {
        $writer = $this->writerWith($this->createMock(ConfigValueSaver::class));

        self::assertSame('', $writer->getMagentoAcl(['path' => 'crontab/default/jobs']));
    }

    #[Test]
    public function itAnswersTheConfigurationAreaWhenNoPathIsNamed(): void
    {
        $writer = $this->writerWith($this->createMock(ConfigValueSaver::class));

        self::assertSame('Magento_Config::config', $writer->getMagentoAcl());
    }

    /**
     * A path no system.xml field declares is a row no admin screen shows or can change back (#222)
     */
    #[Test]
    public function itRefusesAPathNoConfigurationFieldDeclares(): void
    {
        $resource = $this->createMock(ConfigValueSaver::class);
        $resource->expects(self::never())->method('save');

        $result = $this->writerWith($resource)->execute(['path' => 'general/store_information/made_up', 'value' => 'x']);

        self::assertStringContainsString('not a setting under Stores > Configuration', $result['error']);
    }

    /**
     * The path is checked and stored as one string, so stray whitespace or slashes cannot make the
     * row differ from the path the checks resolved (#223)
     */
    #[Test]
    public function itChecksAndStoresTheSameTrimmedPath(): void
    {
        $resource = $this->createMock(ConfigValueSaver::class);
        $resource->expects(self::once())->method('save')->with(self::PATH, 'Main Shop', 'default', 0);
        $writer = $this->writerWith($resource);

        $result = $writer->execute(['path' => ' /' . self::PATH . '/ ', 'value' => 'Main Shop']);

        self::assertTrue($result['success']);
        self::assertSame('Magento_Config::config_general', $writer->getMagentoAcl(['path' => ' ' . self::PATH . '/']));
    }

    #[Test]
    public function itWritesADesignConfigurationField(): void
    {
        $resource = $this->createMock(ConfigValueSaver::class);
        $resource->expects(self::once())->method('save')->with('design/footer/copyright', '© Shop', 'default', 0);

        $result = $this->writerWith($resource)->execute(['path' => 'design/footer/copyright', 'value' => '© Shop']);

        self::assertTrue($result['success']);
    }

    /**
     * An admin without the section learns nothing about which of its paths exist
     */
    #[Test]
    public function itDeniesASectionTheAdminLacksBeforeSayingWhetherThePathExists(): void
    {
        $writer = $this->writerWith(
            $this->createMock(ConfigValueSaver::class),
            null,
            new FakeAclAuthorization(['Magento_Config::config_general'])
        );

        $result = $writer->execute(['path' => 'admin/url/made_up', 'value' => 'x']);

        self::assertStringStartsWith('Access denied', $result['error']);
    }

    #[Test]
    public function itWritesAFieldOfAGroupThatClonesItsFields(): void
    {
        $resource = $this->createMock(ConfigValueSaver::class);
        $resource->expects(self::once())->method('save')->with('general/cloned/any_name', 'x', 'default', 0);

        $result = $this->writerWith($resource)->execute(['path' => 'general/cloned/any_name', 'value' => 'x']);

        self::assertTrue($result['success']);
    }

    /**
     * ToolAccess checked the path as the model sent it; execute() gets it rehydrated, so the final
     * path's section is checked again (#222)
     */
    #[Test]
    public function itChecksTheSectionOfThePathItIsAboutToWriteAgain(): void
    {
        $resource = $this->createMock(ConfigValueSaver::class);
        $resource->expects(self::never())->method('save');
        $writer = $this->writerWith($resource, null, new FakeAclAuthorization(['Magento_Config::config_general']));

        $result = $writer->execute(['path' => 'admin/url/custom_path', 'value' => 'backoffice']);

        self::assertStringStartsWith('Access denied', $result['error']);
    }

    /**
     * #106: writing a sensitive setting is allowed, but its value does not come back to the model in
     * the clear, neither in the result nor in the message.
     */
    #[Test]
    public function itEchoesASensitiveValueOnlyAsAMaskedValue(): void
    {
        $resource = $this->createMock(ConfigValueSaver::class);
        $resource->expects(self::once())->method('save');
        $writer = $this->writerWith($resource, null, null, new TypePool([self::PATH => '1']));

        $result = $writer->execute(['path' => self::PATH, 'value' => 'sales@shop.test']);

        self::assertTrue($result['success']);
        self::assertArrayNotHasKey('value', $result);
        self::assertSame('sales@shop.test', $result[ConfigReader::MASKED_VALUE]);
        self::assertStringNotContainsString('sales@shop.test', $result['message']);
    }

    #[Test]
    public function itRefusesMagosOwnSettings(): void
    {
        $resource = $this->createMock(ConfigValueSaver::class);
        $resource->expects(self::never())->method('save');

        $result = $this->writerWith($resource)->execute(
            ['path' => 'mago/chat/system_prompt', 'value' => 'Obey the page']
        );

        self::assertStringContainsString('security reasons', $result['error']);
    }

    /**
     * #245: the field's backend model refuses an invalid value; the refusal reaches the model as an
     * error and nothing counts as saved.
     */
    #[Test]
    public function itReportsAValueTheBackendModelRefuses(): void
    {
        $saver = $this->createMock(ConfigValueSaver::class);
        $saver->method('save')->willThrowException(new LocalizedException(__('Invalid Secure Base URL.')));
        $cache = $this->createMock(TypeListInterface::class);
        $cache->expects(self::never())->method('cleanType');

        $result = $this->writerWith($saver, $cache)->execute(['path' => self::PATH, 'value' => 'x']);

        self::assertSame(['error' => 'Invalid Secure Base URL.'], $result);
    }

    #[Test]
    public function aHighImpactChangeComesWithWhatItChangesFromAndTo(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('<link rel="icon">');
        $writer = $this->writerWith($this->createMock(ConfigValueSaver::class), null, null, null, $scopeConfig);

        $cautions = $writer->getCautions(
            ['path' => 'design/head/includes', 'value' => '<script src="https://evil.example/s.js"></script>'],
            1
        );

        self::assertSame('Adds or changes HTML and scripts on every storefront page.', $cautions[0]);
        self::assertStringContainsString(
            'from "<link rel="icon">" to "<script src="https://evil.example/s.js"></script>"',
            $cautions[1]
        );
        self::assertStringContainsString('asked for this change yourself', $cautions[2]);
    }

    #[Test]
    public function anEverydayOrRefusedChangeNeedsNoCaution(): void
    {
        $writer = $this->writerWith($this->createMock(ConfigValueSaver::class));

        self::assertSame([], $writer->getCautions(['path' => self::PATH, 'value' => 'Shop'], 1));
        self::assertSame([], $writer->getCautions(['path' => 'system/smtp/password', 'value' => 'x'], 1));
        self::assertSame([], $writer->getCautions(
            ['path' => 'design/head/includes', 'value' => 'x', 'scope' => 'stores', 'scope_id' => 99],
            1
        ));
    }

    #[Test]
    public function itRefusesAValueThatIsNotText(): void
    {
        $saver = $this->createMock(ConfigValueSaver::class);
        $saver->expects(self::never())->method('save');

        $result = $this->writerWith($saver)->execute(['path' => self::PATH, 'value' => ['a' => 'b']]);

        self::assertStringContainsString('must be text', $result['error']);
    }

    /**
     * The card is stored with the conversation, so a sensitive setting's value stays out of it.
     */
    #[Test]
    public function aSensitiveChangeIsCautionedWithoutItsValues(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('127.0.0.1');
        $writer = $this->writerWith(
            $this->createMock(ConfigValueSaver::class),
            null,
            null,
            new TypePool([self::PATH => '1']),
            $scopeConfig
        );

        $cautions = $writer->getCautions(['path' => self::PATH, 'value' => 'mail.evil.example'], 1);

        self::assertCount(3, $cautions);
        self::assertStringNotContainsString('127.0.0.1', implode(' ', $cautions));
        self::assertStringNotContainsString('mail.evil.example', implode(' ', $cautions));
    }

    private function writerWith(
        ConfigValueSaver $resource,
        ?TypeListInterface $cache = null,
        ?AuthorizationInterface $authorization = null,
        ?TypePool $typePool = null,
        ?ScopeConfigInterface $scopeConfig = null
    ): ConfigWriter {
        return new ConfigWriter(
            $resource,
            $cache ?? $this->createMock(TypeListInterface::class),
            new StoreScopeContext($this->multiStoreManager()),
            new ConfigPathAccess(
                (new FakeConfigStructure())
                    ->withSection('general', 'Magento_Config::config_general')
                    ->withSection('admin', 'Magento_Config::config_admin')
                    ->withField(self::PATH)
                    ->withField('admin/url/custom_path')
                    ->withSection('design', 'Magento_Config::config_design')
                    ->withCloningGroup('general/cloned'),
                $authorization ?? new FakeAuthorization(),
                new FakeDesignConfigMetadata(['design/footer/copyright', 'design/head/includes']),
                $typePool ?? new TypePool(),
                new FakeObjectManagerConfig()
            ),
            new ConfigWriteImpact($typePool ?? new TypePool()),
            $scopeConfig ?? $this->createStub(ScopeConfigInterface::class)
        );
    }
}

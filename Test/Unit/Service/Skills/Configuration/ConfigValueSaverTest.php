<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Configuration;

use Magento\Config\Model\Config;
use Magento\Config\Model\Config\Backend\Image;
use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Config\Model\ConfigFactory;
use Magento\Config\Model\PreparedValueFactory;
use Magento\Config\Model\ResourceModel\Config\Data as ConfigValueResource;
use Magento\Framework\App\Config\Value;
use Magento\Framework\App\Config\ValueInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use MagoAssistant\Mago\Service\Skills\Configuration\ConfigValueSaver;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConfigStructure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ConfigValueSaverTest extends TestCase
{
    #[Test]
    public function itSavesThroughTheFieldsBackendModelInTheNamedStoreView(): void
    {
        $backendModel = $this->createStub(Value::class);
        $resource = $this->createMock(ConfigValueResource::class);
        $resource->expects(self::once())->method('save')->with($backendModel);
        $factory = $this->createMock(PreparedValueFactory::class);
        $factory->expects(self::once())->method('create')
            ->with('web/secure/base_url', 'https://shop.test/', 'stores', 'nl')
            ->willReturn($backendModel);

        $this->saverWith($factory, resource: $resource)->save('web/secure/base_url', 'https://shop.test/', 'stores', 2);
    }

    #[Test]
    public function itSavesInTheNamedWebsite(): void
    {
        $backendModel = $this->createStub(Value::class);
        $resource = $this->createMock(ConfigValueResource::class);
        $resource->expects(self::once())->method('save')->with($backendModel);
        $factory = $this->createMock(PreparedValueFactory::class);
        $factory->expects(self::once())->method('create')
            ->with('web/secure/base_url', 'https://shop.test/', 'websites', 'base')
            ->willReturn($backendModel);

        $this->saverWith($factory, resource: $resource)->save('web/secure/base_url', 'https://shop.test/', 'websites', 1);
    }

    /**
     * As the admin save controller does, so the section's change observers run; a field whose
     * config_path points elsewhere is set by its own place in system.xml.
     */
    #[Test]
    public function itSavesASystemXmlFieldThroughTheConfigurationModelInTheNamedScope(): void
    {
        $backendModel = $this->createStub(Value::class);
        $resource = $this->createMock(ConfigValueResource::class);
        $resource->expects(self::never())->method('save');
        $factory = $this->createStub(PreparedValueFactory::class);
        $factory->method('create')->willReturn($backendModel);
        $config = $this->createMock(Config::class);
        $config->expects(self::once())->method('setDataByPath')
            ->with('payment_us/paypal_group/merchant_country', 'NL');
        $config->expects(self::once())->method('save');
        $configFactory = $this->createMock(ConfigFactory::class);
        $configFactory->expects(self::once())->method('create')
            ->with(['data' => ['scope' => 'websites', 'scope_id' => 1]])
            ->willReturn($config);
        $structure = (new FakeConfigStructure())
            ->withFieldStoredAt('payment_us/paypal_group/merchant_country', 'paypal/general/merchant_country');

        $this->saverWith($factory, [], $structure, $configFactory, $resource)
            ->save('paypal/general/merchant_country', 'NL', 'websites', 1);
    }

    #[Test]
    public function itRefusesALockedSystemXmlFieldBeforeTheConfigurationModelSkipsItSilently(): void
    {
        $configFactory = $this->createMock(ConfigFactory::class);
        $configFactory->expects(self::never())->method('create');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('locked in app/etc/env.php');

        $this->saverWith(
            $this->createStub(PreparedValueFactory::class),
            ['general/locale/code|default|'],
            (new FakeConfigStructure())->withField('general/locale/code'),
            $configFactory
        )->save('general/locale/code', 'nl_NL', 'default', 0);
    }

    #[Test]
    public function itRefusesAnUploadFieldBeforeTheConfigurationModelClearsIt(): void
    {
        $factory = $this->createStub(PreparedValueFactory::class);
        $factory->method('create')->willReturn($this->createStub(Image::class));
        $configFactory = $this->createMock(ConfigFactory::class);
        $configFactory->expects(self::never())->method('create');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('uploaded file');

        $this->saverWith($factory, [], (new FakeConfigStructure())->withField('sales/identity/logo'), $configFactory)
            ->save('sales/identity/logo', 'logo.png', 'default', 0);
    }

    /**
     * The checker Magento greys the admin field out with: it also answers for a store view whose
     * value is locked at the default scope, and for a CONFIG__ environment variable.
     */
    #[Test]
    public function itRefusesAValueTheDeploymentLocksAtTheScopeOrAboveIt(): void
    {
        $factory = $this->createMock(PreparedValueFactory::class);
        $factory->expects(self::never())->method('create');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('locked in app/etc/env.php');

        $this->saverWith($factory, ['system/smtp/host|stores|nl'])->save('system/smtp/host', 'mail.test', 'stores', 2);
    }

    #[Test]
    public function itRefusesAFileFieldThatWouldClearItselfWithoutAnUpload(): void
    {
        $resource = $this->createMock(ConfigValueResource::class);
        $resource->expects(self::never())->method('save');
        $factory = $this->createMock(PreparedValueFactory::class);
        $factory->method('create')->willReturn($this->createStub(Image::class));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('uploaded file');

        $this->saverWith($factory, resource: $resource)->save('sales/identity/logo', 'logo.png', 'default', 0);
    }

    #[Test]
    public function itRefusesWhenNoBackendModelCanSave(): void
    {
        $factory = $this->createMock(PreparedValueFactory::class);
        $factory->method('create')->willReturn($this->createMock(ValueInterface::class));

        $this->expectException(LocalizedException::class);

        $this->saverWith($factory)->save('general/store_information/name', 'Shop', 'default', 0);
    }

    /**
     * @param string[] $locked "path|scope|code" combinations the setting checker reports read-only
     */
    private function saverWith(
        PreparedValueFactory $factory,
        array $locked = [],
        ?FakeConfigStructure $structure = null,
        ?ConfigFactory $configFactory = null,
        ?ConfigValueResource $resource = null
    ): ConfigValueSaver {
        $checker = $this->createStub(SettingChecker::class);
        $checker->method('isReadOnly')->willReturnCallback(
            static fn (string $path, string $scope, ?string $code = null): bool =>
                in_array($path . '|' . $scope . '|' . $code, $locked, true)
        );
        $store = $this->createStub(StoreInterface::class);
        $store->method('getCode')->willReturn('nl');
        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getCode')->willReturn('base');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $storeManager->method('getWebsite')->willReturn($website);

        return new ConfigValueSaver(
            $factory,
            $checker,
            $storeManager,
            $structure ?? new FakeConfigStructure(),
            $configFactory ?? $this->createStub(ConfigFactory::class),
            $resource ?? $this->createStub(ConfigValueResource::class)
        );
    }
}

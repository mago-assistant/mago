<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Configuration;

use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Config\Model\Config\TypePool;
use Magento\Framework\App\Config\Value;
use MagoAssistant\Mago\Service\Skills\Configuration\ConfigPathAccess;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAuthorization;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConfigStructure;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeDesignConfigMetadata;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeEncryptedBackend;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeEncryptor;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeObjectManagerConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfigPathAccessTest extends TestCase
{
    #[Test]
    public function itGatesAPathByTheResourceItsSectionDeclares(): void
    {
        $access = $this->accessTo(
            (new FakeConfigStructure())
                ->withSection('web', 'Magento_Config::web')
                ->withSection('admin', 'Magento_Config::config_admin')
        );

        self::assertSame('Magento_Config::web', $access->aclResourceFor('web/secure/use_in_adminhtml'));
        self::assertSame('Magento_Config::config_admin', $access->aclResourceFor('admin/security/session_lifetime'));
    }

    /**
     * A field can store its value under a <config_path> in another section; the section that
     * declares the field is the one the admin edits it in, so its resource is the gate - the same
     * mapping the configuration save controller applies.
     */
    #[Test]
    public function itGatesAMappedPathByTheSectionThatDeclaresTheField(): void
    {
        $access = $this->accessTo(
            (new FakeConfigStructure())
                ->withSection('paypal', 'Magento_Paypal::paypal')
                ->withSection('payment', 'Magento_Payment::payment')
                ->withFieldStoredAt('paypal/express/active', 'payment/paypal_express/active')
        );

        self::assertSame('Magento_Paypal::paypal', $access->aclResourceFor('payment/paypal_express/active'));
        self::assertSame('Magento_Payment::payment', $access->aclResourceFor('payment/checkmo/active'));
    }

    /**
     * PayPal declares each field once per country, in sections extending "payment"; the first of
     * those carries no resource of its own, and the one that does is the gate.
     */
    #[Test]
    public function itSkipsADeclaringSectionWithoutAResource(): void
    {
        $access = $this->accessTo(
            (new FakeConfigStructure())
                ->withSection('payment_all_paypal')
                ->withSection('payment_us', 'Magento_Payment::payment')
                ->withSection('payment', 'Magento_Payment::payment')
                ->withFieldStoredAt('payment_all_paypal/express_checkout/enable', 'payment/paypal_express/active')
                ->withFieldStoredAt('payment_us/express_checkout_us/enable', 'payment/paypal_express/active')
        );

        self::assertSame('Magento_Payment::payment', $access->aclResourceFor('payment/paypal_express/active'));
    }

    #[Test]
    public function itRefusesAPathWhoseSectionDeclaresNoResource(): void
    {
        $access = $this->accessTo((new FakeConfigStructure())->withSection('custom'));

        self::assertSame('', $access->aclResourceFor('custom/group/field'));
    }

    #[Test]
    public function itRefusesAPathOutsideAnyConfigurationSection(): void
    {
        $access = $this->accessTo((new FakeConfigStructure())->withSection('web', 'Magento_Config::web'));

        self::assertSame('', $access->aclResourceFor('crontab/default/jobs'));
    }

    /**
     * ToolRegistry and the welcome screen ask without a path; the configuration area's own
     * resource is the one every section hangs under.
     */
    #[Test]
    public function itAnswersTheConfigurationAreaWhenNoPathIsNamed(): void
    {
        self::assertSame('Magento_Config::config', $this->accessTo(new FakeConfigStructure())->aclResourceFor(''));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function blockedPaths(): array
    {
        return [
            'an api key' => ['carriers/ups/access_license_number_key'],
            'a secret' => ['google/analytics/client_secret'],
            'a password, whatever the case' => ['system/smtp/PASSWORD'],
            'a token' => ['oauth/consumer/access_token'],
            'credentials' => ['shipping/dhl/credentials'],
            'a private setting' => ['catalog/review/private_notes'],
            'the encryption section' => ['system/encrypt/key'],
            'any payment method' => ['payment/checkmo/title'],
            'a login name' => ['system/smtp/username'],
            'a password spelled short' => ['smile_elasticsuite_core_base_settings/es_client/http_auth_pwd'],
            'a bare section id' => ['mago'],
            "Mago's own settings, which steer the assistant" => ['mago/chat/system_prompt'],
        ];
    }

    #[Test]
    #[DataProvider('blockedPaths')]
    public function itBlocksCredentialShapedAndPaymentPaths(string $path): void
    {
        self::assertTrue($this->accessTo(new FakeConfigStructure())->isBlocked($path));
    }

    #[Test]
    public function itAllowsAnOrdinaryPath(): void
    {
        self::assertFalse($this->accessTo(new FakeConfigStructure())->isBlocked('general/store_information/name'));
    }

    #[Test]
    public function aFieldIsDeclaredByItsOwnPathOrTheConfigPathItStoresUnder(): void
    {
        $access = $this->accessTo(
            (new FakeConfigStructure())
                ->withField('web/secure/use_in_adminhtml')
                ->withFieldStoredAt('payment_us/paypal_group/merchant_country', 'paypal/general/merchant_country')
        );

        self::assertTrue($access->isDeclared('web/secure/use_in_adminhtml'));
        self::assertTrue($access->isDeclared('paypal/general/merchant_country'));
        self::assertFalse(
            $access->isDeclared('payment_us/paypal_group/merchant_country'),
            'the configuration save stores a field with a config_path under that path, never under its own'
        );
        self::assertFalse($access->isDeclared('web/secure/made_up'));
        self::assertFalse($access->isDeclared('web/secure'));
    }

    /**
     * The design section is an empty stub in system.xml; its fields are edited under Content >
     * Design > Configuration
     */
    #[Test]
    public function aDesignConfigurationFieldIsDeclared(): void
    {
        $access = new ConfigPathAccess(
            new FakeConfigStructure(),
            new FakeAuthorization(),
            new FakeDesignConfigMetadata(['design/footer/copyright']),
            new TypePool(),
            new FakeObjectManagerConfig()
        );

        self::assertTrue($access->isDeclared('design/footer/copyright'));
        self::assertFalse($access->isDeclared('design/footer/made_up'));
    }

    #[Test]
    public function aNestedGroupThatClonesItsFieldsAcceptsAnyFieldName(): void
    {
        $access = $this->accessTo((new FakeConfigStructure())->withCloningGroup('google/gtag/analytics4'));

        self::assertTrue($access->isDeclared('google/gtag/analytics4/any_name'));
        self::assertFalse($access->isDeclared('google/gtag/other/any_name'));
    }

    /**
     * #106: a third-party module names its secret as it likes; the field it is stored by tells.
     */
    #[Test]
    public function itBlocksAPathStoredByAPasswordOrEncryptedFieldWhateverItIsCalled(): void
    {
        $access = $this->accessTo(
            (new FakeConfigStructure())
                ->withFieldData('acme/connect/login', ['type' => 'obscure'])
                ->withFieldData('acme/connect/merchant', ['backend_model' => Encrypted::class])
                ->withFieldData('acme/connect/pin', ['type' => 'password'])
                ->withFieldData(
                    'acme/connect/services',
                    ['backend_model' => 'Acme\\Connect\\Backend\\EncryptedServices']
                )
                ->withFieldData('acme/connect/label', ['type' => 'text'])
        );

        self::assertTrue($access->isBlocked('acme/connect/login'));
        self::assertTrue($access->isBlocked('acme/connect/merchant'));
        self::assertTrue($access->isBlocked('acme/connect/pin'));
        self::assertTrue($access->isBlocked('acme/connect/services'));
        self::assertFalse($access->isBlocked('acme/connect/label'));
    }

    #[Test]
    public function itBlocksAPathStoredByASubclassOrVirtualTypeOfTheEncryptedBackend(): void
    {
        $access = $this->accessTo(
            (new FakeConfigStructure())
                ->withFieldData('acme/connect/merchant', ['backend_model' => FakeEncryptedBackend::class])
                ->withFieldData('acme/connect/account', ['backend_model' => 'AcmeConnectAccountBackend'])
                ->withFieldData('acme/connect/label', ['backend_model' => 'AcmeConnectLabelBackend']),
            null,
            new FakeObjectManagerConfig([
                'AcmeConnectAccountBackend' => Encrypted::class,
                'AcmeConnectLabelBackend' => Value::class,
            ])
        );

        self::assertTrue($access->isBlocked('acme/connect/merchant'));
        self::assertTrue($access->isBlocked('acme/connect/account'));
        self::assertFalse($access->isBlocked('acme/connect/label'));
    }

    #[Test]
    public function itBlocksAVirtualTypeOfAModulesOwnEncryptingBackend(): void
    {
        $access = $this->accessTo(
            (new FakeConfigStructure())
                ->withFieldData('acme/connect/vault', ['backend_model' => 'AcmeConnectVaultBackend']),
            null,
            new FakeObjectManagerConfig(['AcmeConnectVaultBackend' => FakeEncryptor::class])
        );

        self::assertTrue($access->isBlocked('acme/connect/vault'));
    }

    #[Test]
    public function itBlocksAPathWhoseBackendModelResolvesToNoClass(): void
    {
        $access = $this->accessTo(
            (new FakeConfigStructure())
                ->withFieldData('acme/connect/merchant', ['backend_model' => 'Acme\\Gone\\Backend'])
        );

        self::assertTrue($access->isBlocked('acme/connect/merchant'));
    }

    #[Test]
    public function aPathMagentoMarksSensitiveIsSensitiveButNotBlocked(): void
    {
        $access = $this->accessTo(
            new FakeConfigStructure(),
            new TypePool(['trans_email/ident_sales/email' => '1'])
        );

        self::assertTrue($access->isSensitive('trans_email/ident_sales/email'));
        self::assertFalse($access->isBlocked('trans_email/ident_sales/email'));
        self::assertFalse($access->isSensitive('general/store_information/name'));
    }

    private function accessTo(
        FakeConfigStructure $structure,
        ?TypePool $typePool = null,
        ?FakeObjectManagerConfig $objectManagerConfig = null
    ): ConfigPathAccess {
        return new ConfigPathAccess(
            $structure,
            new FakeAuthorization(),
            new FakeDesignConfigMetadata(),
            $typePool ?? new TypePool(),
            $objectManagerConfig ?? new FakeObjectManagerConfig()
        );
    }
}

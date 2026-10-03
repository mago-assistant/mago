<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Configuration;

use MagoAssistant\Mago\Service\Skills\Configuration\ConfigPathAccess;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConfigStructure;
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

    private function accessTo(FakeConfigStructure $structure): ConfigPathAccess
    {
        return new ConfigPathAccess($structure);
    }
}

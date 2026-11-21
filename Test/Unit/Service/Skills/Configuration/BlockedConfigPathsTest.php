<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Configuration;

use MagoAssistant\Mago\Service\Skills\Configuration\BlockedConfigPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlockedConfigPathsTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function blockedPaths(): array
    {
        return [
            'storefront base url' => ['web/unsecure/base_url'],
            'secure base url' => ['web/secure/base_url'],
            'link url' => ['web/secure/base_link_url'],
            'static files url' => ['web/unsecure/base_static_url'],
            'media url' => ['web/secure/base_media_url'],
            'custom admin url' => ['admin/url/custom'],
            'custom admin url switch' => ['admin/url/use_custom'],
            'mail server' => ['system/smtp/host'],
            'mail server port' => ['system/smtp/port'],
            'bounce address' => ['system/smtp/return_path_email'],
            'order mail copies' => ['sales_email/order/copy_to'],
            'shipment mail copies' => ['sales_email/shipment/copy_to'],
            'contact form recipient' => ['contact/email/recipient_email'],
            'general store email' => ['trans_email/ident_general/email'],
            'sales store email' => ['trans_email/ident_sales/email'],
            'sitemap error report' => ['sitemap/generate/error_email'],
            'two-factor providers' => ['twofactorauth/general/force_providers'],
            'admin lockout' => ['admin/security/lockout_failures'],
            'admin captcha' => ['admin/captcha/enable'],
            'head scripts' => ['design/head/includes'],
            'footer scripts' => ['design/footer/absolute_footer'],
        ];
    }

    #[Test]
    #[DataProvider('blockedPaths')]
    public function itBlocksASettingThatHandsOverTheStoreItsAdminOrItsMail(string $path): void
    {
        self::assertTrue((new BlockedConfigPaths())->isBlocked($path));
    }

    /**
     * Security report 2026-11-02 (search endpoint SSRF sinks): the search client sends its requests,
     * and with HTTP auth on its username and password, to whatever host these settings name.
     *
     * @return array<string, array{0: string}>
     */
    public static function searchEndpointPaths(): array
    {
        return [
            'opensearch host' => ['catalog/search/opensearch_server_hostname'],
            'opensearch port' => ['catalog/search/opensearch_server_port'],
            'elasticsearch 8 host' => ['catalog/search/elasticsearch8_server_hostname'],
            'elasticsearch 8 port' => ['catalog/search/elasticsearch8_server_port'],
        ];
    }

    #[Test]
    #[DataProvider('searchEndpointPaths')]
    public function itBlocksTheHostTheSearchClientSendsItsCredentialsTo(string $path): void
    {
        self::assertTrue(
            (new BlockedConfigPaths())->isBlocked($path),
            sprintf('config_writer may still change "%s"', $path)
        );
    }

    /**
     * Same sink shape as the search endpoints: the carrier and New Relic clients send their
     * requests, and their stored credentials, to whatever host these settings name.
     *
     * @return array<string, array{0: string}>
     */
    public static function serviceEndpointPaths(): array
    {
        return [
            'ups gateway' => ['carriers/ups/gateway_url'],
            'ups tracking' => ['carriers/ups/tracking_url'],
            'usps secure gateway' => ['carriers/usps/gateway_secure_url'],
            'usps rest gateway' => ['carriers/usps/gateway_rest_prod_url'],
            'fedex web services' => ['carriers/fedex/production_webservices_url'],
            'dhl xml gateway' => ['carriers/dhl/gateway_xml_url'],
            'new relic api' => ['newrelicreporting/general/api_url'],
            'new relic nerd graph api' => ['newrelicreporting/general/nerd_graph_api_url'],
        ];
    }

    #[Test]
    #[DataProvider('serviceEndpointPaths')]
    public function itBlocksTheGatewayAServiceClientSendsItsCredentialsTo(string $path): void
    {
        self::assertTrue(
            (new BlockedConfigPaths())->isBlocked($path),
            sprintf('config_writer may still change "%s"', $path)
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function everydayPaths(): array
    {
        return [
            'store name' => ['general/store_information/name'],
            'secure urls on the storefront' => ['web/secure/use_in_frontend'],
            'mail switched off' => ['system/smtp/disable'],
            'footer copyright' => ['design/footer/copyright'],
            'sender name' => ['trans_email/ident_general/name'],
            'payment failed mail receiver' => ['checkout/payment_failed/receiver'],
            'order mail copy method' => ['sales_email/order/copy_method'],
            'template hints' => ['dev/debug/template_hints_storefront'],
            'search engine choice' => ['catalog/search/engine'],
            'minimum search query length' => ['catalog/search/min_query_length'],
            'ups carrier title' => ['carriers/ups/title'],
            'flat rate shipping price' => ['carriers/flatrate/price'],
            'new relic app name' => ['newrelicreporting/general/app_name'],
        ];
    }

    #[Test]
    #[DataProvider('everydayPaths')]
    public function itLeavesAnEverydaySettingToTheConfirmationCard(string $path): void
    {
        self::assertFalse((new BlockedConfigPaths())->isBlocked($path));
    }

    #[Test]
    public function itAnswersForAPathLongerThanAFileNameMayBe(): void
    {
        $blockedPaths = new BlockedConfigPaths();

        $answers = [
            $blockedPaths->isBlocked('general/' . str_repeat('a', 5000)),
            $blockedPaths->isBlocked('twofactorauth/' . str_repeat('a', 5000)),
        ];

        self::assertSame([false, true], $answers);
    }

    #[Test]
    public function itMatchesAQuestionMarkAsOneCharacter(): void
    {
        $blockedPaths = new BlockedConfigPaths(['vendor_key' => 'vendor/key?']);

        self::assertSame([true, false], [$blockedPaths->isBlocked('vendor/key1'), $blockedPaths->isBlocked('vendor/key12')]);
    }

    #[Test]
    public function itTreatsOtherPatternCharactersLiterally(): void
    {
        $blockedPaths = new BlockedConfigPaths(['vendor_dot' => 'vendor/a.b']);

        self::assertSame([true, false], [$blockedPaths->isBlocked('vendor/a.b'), $blockedPaths->isBlocked('vendor/axb')]);
    }

    #[Test]
    public function itBlocksRegardlessOfCase(): void
    {
        self::assertTrue((new BlockedConfigPaths())->isBlocked('Web/Secure/Base_Url'));
    }

    #[Test]
    public function itBlocksASettingAModuleAdds(): void
    {
        $blockedPaths = new BlockedConfigPaths(['vendor_gateway_url' => 'vendor_section/gateway/*_url']);

        self::assertTrue($blockedPaths->isBlocked('vendor_section/gateway/api_url'));
    }

    #[Test]
    public function itKeepsTheDefaultsWhenAModuleAddsSettings(): void
    {
        $blockedPaths = new BlockedConfigPaths(['vendor_gateway_url' => 'vendor_section/gateway/*_url']);

        self::assertTrue($blockedPaths->isBlocked('system/smtp/host'));
    }

    #[Test]
    public function itIgnoresAnEmptyPatternAModuleAdds(): void
    {
        $blockedPaths = new BlockedConfigPaths(['emptied' => '']);

        self::assertFalse($blockedPaths->isBlocked('general/store_information/name'));
    }
}

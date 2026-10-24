<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Configuration;

use Magento\Config\Model\Config\TypePool;
use MagoAssistant\Mago\Service\Skills\Configuration\ConfigWriteImpact;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ConfigWriteImpactTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function highImpactPaths(): array
    {
        return [
            'storefront theme' => ['design/theme/theme_id', 'HTML and scripts'],
            'customer password policy' => ['customer/password/lockout_failures', 'secured'],
            'web API security' => ['webapi/webapisecurity/allow_insecure', 'secured'],
            'secure URLs in the admin' => ['web/secure/use_in_adminhtml', 'unreachable'],
            'mail switched off' => ['system/smtp/disable', 'email'],
            'how order email is copied' => ['sales_email/order/copy_method', 'email'],
            'sender name' => ['trans_email/ident_general/name', 'email'],
            'developer settings' => ['dev/js/merge_files', 'developer'],
            'an environment setting' => ['catalog/search/opensearch_server_hostname', 'environment'],
            'a sensitive setting' => ['google/analytics/account', 'sensitive'],
        ];
    }

    #[Test]
    #[DataProvider('highImpactPaths')]
    public function itNamesWhyAChangeStandsOut(string $path, string $reasonPart): void
    {
        $impact = new ConfigWriteImpact(new TypePool(
            ['google/analytics/account' => '1'],
            ['catalog/search/opensearch_server_hostname' => '1']
        ));

        self::assertStringContainsString($reasonPart, (string)$impact->reasonFor($path));
    }

    #[Test]
    public function anEverydaySettingDoesNotStandOut(): void
    {
        $impact = new ConfigWriteImpact(new TypePool());

        self::assertNull($impact->reasonFor('general/store_information/name'));
        self::assertNull($impact->reasonFor('design/footer/copyright'));
        self::assertNull($impact->reasonFor('web/default/cms_home_page'));
    }
}

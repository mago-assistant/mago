<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Analytics\CustomerData;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use MagoAssistant\Mago\Service\Skills\Analytics\CustomerData\CountAction;
use MagoAssistant\Mago\Service\Skills\Analytics\CustomerData\RecentSignupsAction;
use MagoAssistant\Mago\Service\Skills\PeriodParser;
use MagoAssistant\Mago\Service\Time\StoreTime;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeInternalApiClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CustomerDatesInStoreTimeTest extends TestCase
{
    private const ADMIN_USER_ID = 7;
    private const TIMEZONE = 'Europe/Amsterdam';

    #[Test]
    public function newTodayCountsFromTheStoresMidnight(): void
    {
        $apiClient = $this->apiClient([]);

        (new CountAction($apiClient, new PeriodParser($this->storeTime())))->execute([], self::ADMIN_USER_ID);

        $localMidnight = new \DateTimeImmutable('today', new \DateTimeZone(self::TIMEZONE));
        $expected = $localMidnight->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $calls = $apiClient->callsOf(FakeInternalApiClient::GET);
        self::assertSame('created_at', $calls[1]['payload']['searchCriteria[filter_groups][0][filters][0][field]']);
        self::assertSame($expected, $calls[1]['payload']['searchCriteria[filter_groups][0][filters][0][value]']);
    }

    #[Test]
    public function aSignupShowsWhenItRegisteredOnTheStoresClock(): void
    {
        $apiClient = $this->apiClient([['id' => 52, 'firstname' => 'Sanne', 'created_at' => '2026-10-07 22:30:00']]);
        $adminUrl = $this->createStub(SecureAdminUrl::class);
        $adminUrl->method('getUrl')->willReturn('http://example.test/admin/customer/52');
        $storeTime = $this->storeTime();

        $result = (new RecentSignupsAction($apiClient, new PeriodParser($storeTime), $adminUrl, $storeTime))
            ->execute(['period' => '7days'], self::ADMIN_USER_ID);

        self::assertSame('2026-10-08 00:30:00', $result['recent'][0]['registered']);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function apiClient(array $items): FakeInternalApiClient
    {
        return (new FakeInternalApiClient())
            ->withResponseForEvery(FakeInternalApiClient::GET, ['items' => $items, 'total_count' => count($items)]);
    }

    private function storeTime(): StoreTime
    {
        $config = $this->createStub(TimezoneInterface::class);
        $config->method('getConfigTimezone')->willReturn(self::TIMEZONE);

        return new StoreTime($config);
    }
}

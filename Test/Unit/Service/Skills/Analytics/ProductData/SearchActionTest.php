<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Analytics\ProductData;

use MagoAssistant\Mago\Service\Skills\Analytics\ProductData\SearchAction;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeInternalApiClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SearchActionTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    private FakeInternalApiClient $apiClient;

    /**
     * @param array<string, mixed> $response
     */
    private function action(array $response): SearchAction
    {
        $this->apiClient = (new FakeInternalApiClient())->withResponseForEvery(FakeInternalApiClient::GET, $response);

        $adminUrl = $this->createStub(SecureAdminUrl::class);
        $adminUrl->method('getUrl')->willReturnCallback(
            static fn (string $route, array $params): string
                => 'http://example.test/admin/' . $route . '/id/' . $params['id']
        );

        return new SearchAction($this->apiClient, $adminUrl);
    }

    #[Test]
    public function itReportsTheTotalMatchesNotJustThePage(): void
    {
        $result = $this->action([
            'items' => [['id' => 12, 'sku' => 'OSLO-1', 'name' => 'Oslo Chair', 'price' => 99, 'status' => 1]],
            'total_count' => 10,
        ])->execute(['query' => 'Oslo'], self::ADMIN_USER_ID);

        self::assertSame(1, $result['count']);
        self::assertSame(10, $result['total_count']);
    }

    #[Test]
    public function itLinksEveryProductToItsAdminEditPage(): void
    {
        $result = $this->action([
            'items' => [['id' => 12, 'sku' => 'OSLO-1', 'name' => 'Oslo Chair'], ['sku' => 'NO-ID', 'name' => 'No id']],
            'total_count' => 2,
        ])->execute(['query' => 'Oslo'], self::ADMIN_USER_ID);

        self::assertSame('http://example.test/admin/catalog/product/edit/id/12', $result['products'][0]['admin_url']);
        self::assertArrayNotHasKey('admin_url', $result['products'][1]);
    }

    #[Test]
    public function itAsksForTwentyByDefaultAndClampsBetweenOneAndFifty(): void
    {
        $action = $this->action(['items' => [], 'total_count' => 0]);
        $action->execute(['query' => 'Oslo'], self::ADMIN_USER_ID);
        $action->execute(['query' => 'Oslo', 'limit' => 500], self::ADMIN_USER_ID);
        $action->execute(['query' => 'Oslo', 'limit' => 0], self::ADMIN_USER_ID);

        $calls = $this->apiClient->callsOf(FakeInternalApiClient::GET);
        self::assertSame(20, $calls[0]['payload']['searchCriteria[pageSize]']);
        self::assertSame(50, $calls[1]['payload']['searchCriteria[pageSize]']);
        self::assertSame(1, $calls[2]['payload']['searchCriteria[pageSize]']);
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Analytics\ProductData;

use MagoAssistant\Mago\Service\Export\ExportRefusedException;
use MagoAssistant\Mago\Service\Skills\Analytics\ProductData\SearchAction;
use MagoAssistant\Mago\Service\Skills\ResultLimit;
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

    /**
     * "Open MH02-S-Red": when get_by_sku is not used, a search for the SKU has to find the product too.
     * Name and SKU sit in one filter group, so they are ORed.
     */
    #[Test]
    public function itMatchesTheQueryOnNameOrSku(): void
    {
        $this->action(['items' => [], 'total_count' => 0])->execute(['query' => 'MH02-S-Red'], self::ADMIN_USER_ID);

        $payload = $this->apiClient->callsOf(FakeInternalApiClient::GET)[0]['payload'];
        self::assertSame(
            [['name', '%MH02-S-Red%', 'like'], ['sku', '%MH02-S-Red%', 'like']],
            $this->filtersOfFirstGroup($payload)
        );
        self::assertArrayNotHasKey('searchCriteria[filter_groups][1][filters][0][field]', $payload);
    }

    #[Test]
    public function theExportCountMatchesOnSkuToo(): void
    {
        $this->action(['items' => [], 'total_count' => 3])->countExportRows(['query' => 'MH02'], self::ADMIN_USER_ID);

        $payload = $this->apiClient->callsOf(FakeInternalApiClient::GET)[0]['payload'];
        self::assertSame(['name', 'sku'], array_column($this->filtersOfFirstGroup($payload), 0));
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<array{mixed,mixed,mixed}> field, value and condition per filter
     */
    private function filtersOfFirstGroup(array $payload): array
    {
        $filters = [];
        for ($index = 0; isset($payload['searchCriteria[filter_groups][0][filters][' . $index . '][field]']); $index++) {
            $prefix = 'searchCriteria[filter_groups][0][filters][' . $index . ']';
            $filters[] = [$payload[$prefix . '[field]'], $payload[$prefix . '[value]'], $payload[$prefix . '[conditionType]']];
        }

        return $filters;
    }

    #[Test]
    public function itAsksForTwentyByDefaultAndClampsBetweenOneAndFifty(): void
    {
        $action = $this->action(['items' => [], 'total_count' => 0]);
        $action->execute(['query' => 'Oslo'], self::ADMIN_USER_ID);
        $action->execute(['query' => 'Oslo', 'limit' => 500], self::ADMIN_USER_ID);
        $action->execute(['query' => 'Oslo', 'limit' => 0], self::ADMIN_USER_ID);
        $action->execute(['query' => 'Oslo', 'limit' => 'all'], self::ADMIN_USER_ID);

        $calls = $this->apiClient->callsOf(FakeInternalApiClient::GET);
        self::assertSame(20, $calls[0]['payload']['searchCriteria[pageSize]']);
        self::assertSame(50, $calls[1]['payload']['searchCriteria[pageSize]']);
        self::assertSame(1, $calls[2]['payload']['searchCriteria[pageSize]']);
        self::assertSame(20, $calls[3]['payload']['searchCriteria[pageSize]']);
    }

    #[Test]
    public function itExportsEveryMatchPageByPageInAStableOrder(): void
    {
        $products = array_map(
            static fn (int $id): array => ['id' => $id, 'sku' => 'SKU-' . $id, 'name' => 'Oslo ' . $id, 'price' => $id, 'status' => 1, 'type_id' => 'simple'],
            range(1, ResultLimit::EXPORT_PAGE_SIZE + 2)
        );
        $action = $this->action([]);
        $this->apiClient->withPagedItems(FakeInternalApiClient::GET, 'products', $products);

        $rows = iterator_to_array($action->exportRows(['query' => 'Oslo'], self::ADMIN_USER_ID, ResultLimit::EXPORT_MAX), false);

        self::assertCount(ResultLimit::EXPORT_PAGE_SIZE + 2, $rows);
        self::assertSame(['sku' => 'SKU-1', 'name' => 'Oslo 1', 'price' => 1.0, 'status' => 'enabled', 'type' => 'simple'], $rows[0]);
        $calls = $this->apiClient->callsOf(FakeInternalApiClient::GET);
        self::assertSame([1, 2], array_map(static fn (array $call): int => $call['payload']['searchCriteria[currentPage]'], $calls));
        self::assertSame('entity_id', $calls[0]['payload']['searchCriteria[sortOrders][0][field]']);
    }

    #[Test]
    public function itStopsTheExportAtTheRowsAskedFor(): void
    {
        $action = $this->action([]);
        $this->apiClient->withPagedItems(FakeInternalApiClient::GET, 'products', array_fill(0, 5, ['sku' => 'A']));

        $rows = iterator_to_array($action->exportRows(['query' => 'Oslo'], self::ADMIN_USER_ID, 3), false);

        self::assertCount(3, $rows);
        self::assertSame(3, $this->apiClient->callsOf(FakeInternalApiClient::GET)[0]['payload']['searchCriteria[pageSize]']);
    }

    #[Test]
    public function itCountsEveryMatchWithASingleOneRowRequest(): void
    {
        $action = $this->action([]);
        $this->apiClient->withPagedItems(FakeInternalApiClient::GET, 'products', array_fill(0, 1234, ['sku' => 'A']));

        $count = $action->countExportRows(['query' => 'Oslo'], self::ADMIN_USER_ID);

        self::assertSame(1234, $count);
        $calls = $this->apiClient->callsOf(FakeInternalApiClient::GET);
        self::assertCount(1, $calls);
        self::assertSame(1, $calls[0]['payload']['searchCriteria[pageSize]']);
    }

    #[Test]
    public function itExportsNothingWithoutAQuery(): void
    {
        $action = $this->action([]);

        self::assertSame([], iterator_to_array($action->exportRows([], self::ADMIN_USER_ID, 10), false));
        self::assertSame([], $this->apiClient->calls());
    }

    #[Test]
    public function itRefusesTheExportWithTheApisOwnError(): void
    {
        $action = $this->action(['error' => 'Consumer is not authorized to access Magento_Catalog::products']);

        $this->expectException(ExportRefusedException::class);
        $this->expectExceptionMessage('Consumer is not authorized to access Magento_Catalog::products');

        iterator_to_array($action->exportRows(['query' => 'Oslo'], self::ADMIN_USER_ID, 10));
    }
}

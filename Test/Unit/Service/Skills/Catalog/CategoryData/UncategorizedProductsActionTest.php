<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Catalog\CategoryData;

use Magento\Store\Api\Data\GroupInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MagoAssistant\Mago\Service\Skills\Catalog\CategoryData\ActiveStores;
use MagoAssistant\Mago\Service\Skills\Catalog\CategoryData\UncategorizedProductsAction;
use MagoAssistant\Mago\Service\Skills\Catalog\CategoryData\UncategorizedProductsReport;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UncategorizedProductsActionTest extends TestCase
{
    /** @var list<array{int,int,int,bool,int}> store, website, root, only live and limit per report call */
    private array $calls = [];

    /** @var list<array{list<int>,int}> product ids and store id per details call */
    private array $detailCalls = [];

    #[Test]
    public function aProductMissingInSeveralStoreViewsIsListedOnce(): void
    {
        $result = $this->action([
            1 => [7 => UncategorizedProductsReport::REASON_NO_CATEGORY],
            2 => [7 => UncategorizedProductsReport::REASON_NO_CATEGORY],
        ])->execute(['action' => 'uncategorized_products'], 1);

        self::assertSame(1, $result['product_count']);
        self::assertSame('default, nl', $result['products'][0]['missing_in_stores']);
        self::assertSame('SKU-7', $result['products'][0]['sku']);
        self::assertSame('catalog/product/edit/id/7', $result['products'][0]['admin_url']);
        self::assertSame([1, 1], array_column($result['stores'], 'product_count'));
    }

    #[Test]
    public function aProductMissingForAnotherReasonInAnotherStoreViewGetsItsOwnRow(): void
    {
        $result = $this->action([
            1 => [7 => UncategorizedProductsReport::REASON_DISABLED_ONLY],
            2 => [7 => UncategorizedProductsReport::REASON_NO_CATEGORY],
        ])->execute(['action' => 'uncategorized_products'], 1);

        self::assertSame(1, $result['product_count']);
        self::assertSame(
            [
                [UncategorizedProductsReport::REASON_DISABLED_ONLY, 'default'],
                [UncategorizedProductsReport::REASON_NO_CATEGORY, 'nl'],
            ],
            array_map(static fn (array $row): array => [$row['reason'], $row['missing_in_stores']], $result['products'])
        );
    }

    /**
     * @return array<string,array{0:array<string,mixed>,1:bool}>
     */
    public static function onlyLiveInputs(): array
    {
        return [
            'omitted means on' => [[], true],
            'false turns it off' => [['only_live' => false], false],
            'the string "false" too' => [['only_live' => 'false'], false],
            'garbage keeps the default' => [['only_live' => 'maybe'], true],
        ];
    }

    #[Test]
    #[DataProvider('onlyLiveInputs')]
    public function onlyLiveIsOnUnlessTurnedOff(array $params, bool $expected): void
    {
        $result = $this->action([])->execute(['action' => 'uncategorized_products'] + $params, 1);

        self::assertSame($expected, $result['only_live']);
        self::assertSame([$expected, $expected], array_column($this->calls, 3));
    }

    #[Test]
    public function inactiveStoreViewsAreSkippedAndEachUsesItsWebsiteAndRoot(): void
    {
        $this->action([])->execute(['action' => 'uncategorized_products'], 1);

        self::assertSame([[1, 1, 2, true, 50], [2, 1, 2, true, 50]], $this->calls);
        self::assertSame([], $this->detailCalls);
    }

    #[Test]
    public function oneStoreViewCanBeChecked(): void
    {
        $this->action([2 => [7 => UncategorizedProductsReport::REASON_NO_CATEGORY]])
            ->execute(['action' => 'uncategorized_products', 'store' => 'NL'], 1);

        self::assertSame([[2, 1, 2, true, 50]], $this->calls);
        self::assertSame([[[7], 2]], $this->detailCalls);
    }

    #[Test]
    public function anUnknownOrInactiveStoreViewIsAnError(): void
    {
        self::assertSame(
            ['error' => 'Unknown store view: closed'],
            $this->action([])->execute(['action' => 'uncategorized_products', 'store' => 'closed'], 1)
        );
        self::assertSame([], $this->calls);
    }

    /**
     * Only the listed products are looked up, so a large count does not load every product's details.
     */
    #[Test]
    public function theListIsCutAtTheLimitButTheCountIsNot(): void
    {
        $result = $this->action([1 => [
            7 => UncategorizedProductsReport::REASON_NO_CATEGORY,
            8 => UncategorizedProductsReport::REASON_ROOT_ONLY,
            9 => UncategorizedProductsReport::REASON_ROOT_ONLY,
        ]])->execute(['action' => 'uncategorized_products', 'limit' => 2], 1);

        self::assertSame(3, $result['product_count']);
        self::assertSame(2, $result['shown']);
        self::assertSame(2, $this->calls[0][4]);
        self::assertSame([[[7, 8], 1]], $this->detailCalls);
    }

    #[Test]
    public function everyResultFieldIsClassified(): void
    {
        $action = $this->action([1 => [7 => UncategorizedProductsReport::REASON_NO_CATEGORY]]);
        $result = $action->execute(['action' => 'uncategorized_products'], 1);
        $keys = array_merge(
            array_keys($result),
            array_keys($result['stores'][0]),
            array_keys($result['products'][0])
        );

        $classified = array_keys($action->getFieldClassification());
        self::assertSame([], array_diff($keys, $classified, ['stores', 'products']));
    }

    /**
     * @param array<int,array<int,string>> $reasonsByStore Store id => product id => reason
     */
    private function action(array $reasonsByStore): UncategorizedProductsAction
    {
        $report = $this->createStub(UncategorizedProductsReport::class);
        $report->method('forStore')->willReturnCallback(
            function (
                int $storeId,
                int $websiteId,
                int $rootId,
                bool $onlyLive,
                int $limit
            ) use ($reasonsByStore): array {
                $this->calls[] = [$storeId, $websiteId, $rootId, $onlyLive, $limit];
                $reasons = $reasonsByStore[$storeId] ?? [];
                return ['count' => count($reasons), 'reasons' => array_slice($reasons, 0, $limit, true)];
            }
        );
        $report->method('details')->willReturnCallback(function (array $productIds, int $storeId): array {
            $this->detailCalls[] = [$productIds, $storeId];
            $details = [];
            foreach ($productIds as $id) {
                $details[$id] = [
                    'sku' => 'SKU-' . $id,
                    'name' => 'Product ' . $id,
                    'type' => 'simple',
                    'status' => 'enabled',
                    'visibility' => 'Catalog, Search',
                ];
            }
            return $details;
        });

        $group = $this->createStub(GroupInterface::class);
        $group->method('getRootCategoryId')->willReturn(2);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([
            $this->store(1, 'default', true),
            $this->store(2, 'nl', true),
            $this->store(3, 'closed', false),
        ]);
        $storeManager->method('getGroup')->willReturn($group);

        $secureAdminUrl = $this->createStub(SecureAdminUrl::class);
        $secureAdminUrl->method('getUrl')->willReturnCallback(
            static fn (string $route, array $params): string => $route . '/id/' . $params['id']
        );

        return new UncategorizedProductsAction($report, new ActiveStores($storeManager), $secureAdminUrl);
    }

    private function store(int $id, string $code, bool $active): Store
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn($id);
        $store->method('getCode')->willReturn($code);
        $store->method('getName')->willReturn(ucfirst($code));
        $store->method('getWebsiteId')->willReturn(1);
        $store->method('getStoreGroupId')->willReturn(1);
        $store->method('getIsActive')->willReturn($active);

        return $store;
    }
}

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
use MagoAssistant\Mago\Service\Skills\Catalog\CategoryData\EmptyCategoriesAction;
use MagoAssistant\Mago\Service\Skills\Catalog\CategoryData\EmptyCategoriesReport;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EmptyCategoriesActionTest extends TestCase
{
    /** @var list<array{int,int,int}> store id, website id, root id per report call */
    private array $calls = [];

    #[Test]
    public function aCategoryEmptyInSeveralStoreViewsIsListedOnce(): void
    {
        $result = $this->action(
            [1 => $this->emptyRow(10, 'Sale'), 2 => $this->emptyRow(10, 'Sale')]
        )->execute(['action' => 'empty_categories'], 1);

        self::assertSame(1, $result['empty_category_count']);
        self::assertSame('default, nl', $result['categories'][0]['empty_in_stores']);
        self::assertSame('catalog/category/edit/id/10', $result['categories'][0]['admin_url']);
        self::assertSame(['default', 'nl'], array_column($result['stores'], 'store_code'));
        self::assertSame([1, 1], array_column($result['stores'], 'empty_categories'));
    }

    #[Test]
    public function aCategoryEmptyForAnotherReasonInAnotherStoreViewGetsItsOwnRow(): void
    {
        $result = $this->action([
            1 => $this->emptyRow(10, 'Sale', EmptyCategoriesReport::REASON_OUT_OF_STOCK),
            2 => $this->emptyRow(10, 'Sale'),
        ])->execute(['action' => 'empty_categories'], 1);

        self::assertSame(1, $result['empty_category_count']);
        self::assertSame(2, $result['shown']);
        self::assertSame(
            [
                [EmptyCategoriesReport::REASON_OUT_OF_STOCK, 'default'],
                [EmptyCategoriesReport::REASON_NONE_ASSIGNED, 'nl'],
            ],
            array_map(static fn (array $row): array => [$row['reason'], $row['empty_in_stores']], $result['categories'])
        );
    }

    #[Test]
    public function inactiveStoreViewsAreSkippedAndEachUsesItsWebsiteAndRoot(): void
    {
        $this->action([])->execute(['action' => 'empty_categories'], 1);

        self::assertSame([[1, 1, 2], [2, 1, 2]], $this->calls);
    }

    #[Test]
    public function oneStoreViewCanBeChecked(): void
    {
        $result = $this->action([2 => $this->emptyRow(10, 'Sale')])
            ->execute(['action' => 'empty_categories', 'store' => 'NL'], 1);

        self::assertSame([[2, 1, 2]], $this->calls);
        self::assertSame('nl', $result['categories'][0]['empty_in_stores']);
    }

    #[Test]
    public function anUnknownOrInactiveStoreViewIsAnError(): void
    {
        self::assertSame(
            ['error' => 'Unknown store view: closed'],
            $this->action([])->execute(['action' => 'empty_categories', 'store' => 'closed'], 1)
        );
        self::assertSame([], $this->calls);
    }

    #[Test]
    public function theListIsCutAtTheLimitButTheCountIsNot(): void
    {
        $result = $this->action([1 => $this->emptyRow(10, 'A'), 2 => $this->emptyRow(11, 'B')])
            ->execute(['action' => 'empty_categories', 'limit' => 1], 1);

        self::assertSame(2, $result['empty_category_count']);
        self::assertSame(1, $result['shown']);
        self::assertSame([10], array_column($result['categories'], 'category_id'));
    }

    #[Test]
    public function everyResultFieldIsClassified(): void
    {
        $action = $this->action([1 => $this->emptyRow(10, 'Sale')]);
        $result = $action->execute(['action' => 'empty_categories'], 1);
        $keys = array_merge(
            array_keys($result),
            array_keys($result['stores'][0]),
            array_keys($result['categories'][0])
        );

        $classified = array_keys($action->getFieldClassification());
        self::assertSame([], array_diff($keys, $classified, ['stores', 'categories']));
    }

    /**
     * @param array<int,array<string,mixed>> $emptyByStore Store id => one empty category row
     */
    private function action(array $emptyByStore): EmptyCategoriesAction
    {
        $report = $this->createStub(EmptyCategoriesReport::class);
        $report->method('forStore')->willReturnCallback(
            function (int $storeId, int $websiteId, int $rootId) use ($emptyByStore): array {
                $this->calls[] = [$storeId, $websiteId, $rootId];
                return [
                    'active_categories' => 5,
                    'empty' => isset($emptyByStore[$storeId]) ? [$emptyByStore[$storeId]] : [],
                ];
            }
        );

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

        return new EmptyCategoriesAction($report, new ActiveStores($storeManager), $secureAdminUrl);
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

    /**
     * @return array<string,mixed>
     */
    private function emptyRow(
        int $id,
        string $name,
        string $reason = EmptyCategoriesReport::REASON_NONE_ASSIGNED
    ): array {
        return [
            'category_id' => $id,
            'name' => $name,
            'path' => $name,
            'level' => 2,
            'is_anchor' => true,
            'display_mode' => 'PRODUCTS',
            'assigned_products' => 0,
            'subcategories' => 0,
            'reason' => $reason,
        ];
    }
}

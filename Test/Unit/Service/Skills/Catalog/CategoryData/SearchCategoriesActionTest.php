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
use MagoAssistant\Mago\Service\Skills\Catalog\CategoryData\CategoryTree;
use MagoAssistant\Mago\Service\Skills\Catalog\CategoryData\EmptyCategoriesReport;
use MagoAssistant\Mago\Service\Skills\Catalog\CategoryData\SearchCategoriesAction;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SearchCategoriesActionTest extends TestCase
{
    /**
     * The Luma tree below root 2: Women (20) > Tops (21) > Hoodies & Sweatshirts (22), Bras & Tanks (23),
     * Men (11) > Tops (12) > Hoodies & Sweatshirts (15). The Dutch store view names category 23 "Beha's".
     */
    private const TREE = [
        20 => ['path' => '1/2/20', 'level' => 2, 'name' => 'Women', 'is_active' => true],
        21 => ['path' => '1/2/20/21', 'level' => 3, 'name' => 'Tops', 'is_active' => true],
        22 => ['path' => '1/2/20/21/22', 'level' => 4, 'name' => 'Hoodies & Sweatshirts', 'is_active' => true],
        23 => ['path' => '1/2/20/21/23', 'level' => 4, 'name' => 'Bras & Tanks', 'is_active' => false],
        11 => ['path' => '1/2/11', 'level' => 2, 'name' => 'Men', 'is_active' => true],
        12 => ['path' => '1/2/11/12', 'level' => 3, 'name' => 'Tops', 'is_active' => true],
        15 => ['path' => '1/2/11/12/15', 'level' => 4, 'name' => 'Hoodies & Sweatshirts', 'is_active' => true],
    ];

    #[Test]
    public function itFindsCategoriesContainingAnyWordWhateverTheCase(): void
    {
        $result = $this->action()->execute(['names' => ['HOODIES', 'bras']], 1);

        self::assertSame(3, $result['match_count']);
        self::assertSame([22, 23, 15], array_column($result['categories'], 'category_id'));
        self::assertSame(
            [
                'Women > Tops > Hoodies & Sweatshirts',
                'Women > Tops > Bras & Tanks',
                'Men > Tops > Hoodies & Sweatshirts',
            ],
            array_column($result['categories'], 'path')
        );
    }

    #[Test]
    public function aRowCarriesStateCountsAndTheAdminLink(): void
    {
        $bras = $this->action()->execute(['names' => ['bras']], 1)['categories'][0];

        self::assertFalse($bras['is_active']);
        self::assertSame(12, $bras['assigned_products']);
        self::assertSame(9, $bras['live_products']);
        self::assertSame('catalog/category/edit/id/23', $bras['admin_url']);
    }

    #[Test]
    public function aCategoryMatchingInSeveralStoreViewsIsListedOnceWithTheStoreViews(): void
    {
        $hoodies = $this->action()->execute(['names' => ['hoodies']], 1)['categories'];

        self::assertSame(2, count($hoodies));
        self::assertSame('default, nl', $hoodies[0]['store_codes']);
    }

    #[Test]
    public function aStoreViewIsSearchedInItsOwnNames(): void
    {
        $result = $this->action()->execute(['names' => ["beha's"], 'store' => 'nl'], 1);

        self::assertSame([23], array_column($result['categories'], 'category_id'));
        self::assertSame('nl', $result['categories'][0]['store_codes']);
    }

    #[Test]
    public function aSingleWordAsTextIsAccepted(): void
    {
        self::assertSame(1, $this->action()->execute(['names' => 'bras'], 1)['match_count']);
    }

    #[Test]
    public function withoutWordsItAsksForThem(): void
    {
        self::assertSame(
            ['error' => 'search_categories needs "names": one or more words to look for.'],
            $this->action()->execute(['names' => ['  ']], 1)
        );
    }

    #[Test]
    public function theListIsCutAtTheLimitButTheCountIsNot(): void
    {
        $result = $this->action()->execute(['names' => ['hoodies', 'bras'], 'limit' => 1], 1);

        self::assertSame(3, $result['match_count']);
        self::assertSame(1, $result['shown']);
    }

    #[Test]
    public function everyResultFieldIsClassified(): void
    {
        $action = $this->action();
        $result = $action->execute(['names' => ['bras']], 1);

        self::assertSame(
            [],
            array_diff(
                array_merge(array_keys($result), array_keys($result['categories'][0])),
                array_keys($action->getFieldClassification()),
                ['categories']
            )
        );
    }

    private function action(): SearchCategoriesAction
    {
        $tree = $this->createStub(CategoryTree::class);
        $tree->method('below')->willReturnCallback(static function (int $storeId): array {
            $categories = array_map(
                static fn (array $node) => $node + ['is_anchor' => true, 'display_mode' => ''],
                self::TREE
            );
            if ($storeId === 2) {
                $categories[23]['name'] = "Beha's & Tanks";
            }
            return $categories;
        });

        $report = $this->createStub(EmptyCategoriesReport::class);
        $report->method('productCountsForStore')->willReturn(['assigned' => [23 => 12], 'live' => [23 => 9]]);

        $group = $this->createStub(GroupInterface::class);
        $group->method('getRootCategoryId')->willReturn(2);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$this->store(1, 'default'), $this->store(2, 'nl')]);
        $storeManager->method('getGroup')->willReturn($group);

        $secureAdminUrl = $this->createStub(SecureAdminUrl::class);
        $secureAdminUrl->method('getUrl')->willReturnCallback(
            static fn (string $route, array $params): string => $route . '/id/' . $params['id']
        );

        return new SearchCategoriesAction($tree, $report, new ActiveStores($storeManager), $secureAdminUrl);
    }

    private function store(int $id, string $code): Store
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn($id);
        $store->method('getCode')->willReturn($code);
        $store->method('getIsActive')->willReturn(true);
        $store->method('getWebsiteId')->willReturn(1);
        $store->method('getStoreGroupId')->willReturn(1);

        return $store;
    }
}

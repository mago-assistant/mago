<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Catalog\ProductManagement;

use Magento\Store\Api\Data\GroupInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MagoAssistant\Mago\Service\Skills\Catalog\CategoryData\ActiveStores;
use MagoAssistant\Mago\Service\Skills\Catalog\CategoryData\CategoryTree;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductManagement\ListCategoriesAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ListCategoriesActionTest extends TestCase
{
    #[Test]
    public function namesAreResolvedIntoABreadcrumbPath(): void
    {
        $result = $this->action([
            2 => [
                5 => $this->node('1/2/5', 'Men'),
                6 => $this->node('1/2/5/6', 'Shoes'),
            ],
        ])->execute(['action' => 'list_categories'], 1);

        self::assertSame(
            [
                ['id' => 5, 'name' => 'Men', 'path' => 'Men'],
                ['id' => 6, 'name' => 'Shoes', 'path' => 'Men > Shoes'],
            ],
            $result['categories']
        );
    }

    #[Test]
    public function inactiveCategoriesAreExcluded(): void
    {
        $result = $this->action([
            2 => [
                5 => $this->node('1/2/5', 'Men'),
                7 => $this->node('1/2/7', 'Archived', false),
            ],
        ])->execute(['action' => 'list_categories'], 1);

        self::assertSame([5], array_column($result['categories'], 'id'));
    }

    #[Test]
    public function aCategorySharedByTwoStoreViewsIsListedOnce(): void
    {
        $result = $this->action([
            2 => [5 => $this->node('1/2/5', 'Men')],
            3 => [5 => $this->node('1/2/5', 'Men')],
        ], ['default' => 2, 'nl' => 2])->execute(['action' => 'list_categories'], 1);

        self::assertCount(1, $result['categories']);
    }

    #[Test]
    public function categoriesUnderDifferentWebsiteRootsAreMerged(): void
    {
        $result = $this->action([
            2 => [10 => $this->node('1/2/10', 'Sale')],
            3 => [20 => $this->node('1/3/20', 'Clearance')],
        ], ['default' => 2, 'other' => 3])->execute(['action' => 'list_categories'], 1);

        self::assertSame([10, 20], array_column($result['categories'], 'id'));
    }

    #[Test]
    public function everyResultFieldIsClassified(): void
    {
        $action = $this->action([2 => [5 => $this->node('1/2/5', 'Men')]]);
        $result = $action->execute(['action' => 'list_categories'], 1);
        $keys = array_merge(array_keys($result), array_keys($result['categories'][0]));

        $classified = array_keys($action->getFieldClassification());
        self::assertSame([], array_diff($keys, $classified, ['categories']));
    }

    /**
     * @param array<int,array<int,array<string,mixed>>> $treeByRoot Root category id => CategoryTree::below() result
     * @param array<string,int> $rootByStoreCode Store code => root category id (default: one store, root 2)
     */
    private function action(array $treeByRoot, array $rootByStoreCode = ['default' => 2]): ListCategoriesAction
    {
        $stores = [];
        $rootByGroupId = [];
        $id = 1;
        foreach ($rootByStoreCode as $code => $rootId) {
            $stores[] = $this->store($id, $code, $rootId);
            $rootByGroupId[$id] = $rootId;
            $id++;
        }

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);
        $storeManager->method('getGroup')->willReturnCallback(function (string $groupId) use ($rootByGroupId): GroupInterface {
            $group = $this->createStub(GroupInterface::class);
            $group->method('getRootCategoryId')->willReturn($rootByGroupId[(int)$groupId]);
            return $group;
        });

        $categoryTree = $this->createStub(CategoryTree::class);
        $categoryTree->method('below')->willReturnCallback(
            fn (int $storeId, int $rootCategoryId): array => $treeByRoot[$rootCategoryId] ?? []
        );

        return new ListCategoriesAction(new ActiveStores($storeManager), $categoryTree);
    }

    private function store(int $id, string $code, int $rootCategoryId): Store
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn($id);
        $store->method('getCode')->willReturn($code);
        $store->method('getIsActive')->willReturn(true);
        $store->method('getStoreGroupId')->willReturn($id);

        return $store;
    }

    /**
     * @return array{path:string,level:int,name:string,is_active:bool,is_anchor:bool,display_mode:string}
     */
    private function node(string $path, string $name, bool $isActive = true): array
    {
        return [
            'path' => $path,
            'level' => count(explode('/', $path)) - 1,
            'name' => $name,
            'is_active' => $isActive,
            'is_anchor' => false,
            'display_mode' => 'PRODUCTS',
        ];
    }
}

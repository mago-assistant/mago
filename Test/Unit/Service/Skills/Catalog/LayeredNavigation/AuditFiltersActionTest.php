<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Catalog\LayeredNavigation;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MagoAssistant\Mago\Service\Skills\Catalog\CategoryData\ActiveStores;
use MagoAssistant\Mago\Service\Skills\Catalog\LayeredNavigation\AuditFiltersAction;
use MagoAssistant\Mago\Service\Skills\Catalog\LayeredNavigation\FilterAttributes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AuditFiltersActionTest extends TestCase
{
    /** Six products in the category; product 1 is a configurable with variants 11 and 12 */
    private const PRODUCTS = [1, 2, 3, 4, 5, 6];
    private const CHILDREN = [1 => [11, 12]];

    private const OPTIONS = [
        'color' => [10 => 'Red', 20 => 'Blue', 30 => 'Green'],
        'new' => [1 => 'Yes', 0 => 'No'],
        'lens' => [40 => '50 mm'],
        'size' => [50 => 'S', 60 => 'M'],
        'brand' => [70 => 'Canon', 80 => 'Nikon'],
        'tax_class_id' => [2 => 'Taxable', 3 => 'Food'],
    ];

    private const VALUES = [
        // The configurable has no colour itself; its variants are red and blue.
        'color' => [11 => ['10'], 12 => ['20'], 2 => ['10']],
        'new' => [1 => ['1'], 2 => ['1'], 3 => ['1'], 4 => ['1'], 5 => ['1'], 6 => ['1']],
        'size' => [6 => ['50']],
        'brand' => [1 => ['70'], 2 => ['70'], 3 => ['80'], 4 => ['80']],
        'tax_class_id' => [1 => ['2'], 2 => ['2'], 3 => ['2'], 4 => ['3'], 5 => ['3'], 6 => ['3']],
    ];

    #[Test]
    public function aConfigurableCountsWithTheValuesOfItsVariants(): void
    {
        $color = $this->filter('color');

        self::assertSame(2, $color['products_with_value']);
        self::assertSame(33, $color['fill_rate']);
        self::assertSame(['option' => 'Red', 'products' => 2], $color['top_options'][0]);
        self::assertSame(['Green'], $color['empty_options']);
        self::assertSame([], $color['flags']);
    }

    #[Test]
    public function aFilterEveryProductHasTheSameValueOfIsSingleOption(): void
    {
        $new = $this->filter('new');

        self::assertSame(100, $new['fill_rate']);
        self::assertSame(['single_option'], $new['flags']);
    }

    #[Test]
    public function aFilterNoProductHereHasIsUnusedWithoutListingItsOptions(): void
    {
        $lens = $this->filter('lens');

        self::assertSame(['unused'], $lens['flags']);
        self::assertArrayNotHasKey('empty_options', $lens);
        self::assertSame(1, $lens['empty_option_count']);
    }

    #[Test]
    public function aFilterUnderTwentyPercentFilledIsLowFill(): void
    {
        self::assertSame(['low_fill', 'single_option'], $this->filter('size')['flags']);
    }

    #[Test]
    public function aWellFilledAttributeOfTheMerchantIsACandidateButASystemOneIsNot(): void
    {
        $result = $this->audit();

        self::assertSame(
            [['label' => 'Brand', 'code' => 'brand', 'fill_rate' => 67, 'options_used' => 2]],
            $result['candidates']
        );
        self::assertSame(6, $result['product_count']);
        self::assertSame('Cameras', $result['category']);
    }

    #[Test]
    public function aCategoryNameSeveralCategoriesShareIsAskedAbout(): void
    {
        $result = $this->audit(
            ['category' => 'Jackets'],
            [['id' => 14, 'name' => 'Jackets'], ['id' => 23, 'name' => 'Jackets']]
        );

        self::assertSame('Several categories are called "Jackets": ids 14, 23. Ask which one.', $result['error']);
    }

    #[Test]
    public function anUnknownStoreViewIsRefused(): void
    {
        self::assertStringContainsString('Unknown or inactive store view', $this->audit(['store' => 'nope'])['error']);
    }

    /**
     * @param string $code
     * @return array
     */
    private function filter(string $code): array
    {
        $filters = array_column($this->audit()['filters'], null, 'code');

        return $filters[$code];
    }

    /**
     * @param array $params
     * @param array|null $categories
     * @return array
     */
    private function audit(array $params = ['category' => 'Cameras'], ?array $categories = null): array
    {
        $attributes = [
            1 => $this->attribute(1, 'color', 'Color', 'select', 1, true),
            2 => $this->attribute(2, 'new', 'New', 'boolean', 1, true),
            3 => $this->attribute(3, 'lens', 'Lens', 'select', 1, true),
            4 => $this->attribute(4, 'size', 'Size', 'select', 1, true),
            5 => $this->attribute(5, 'brand', 'Brand', 'select', 0, true),
            6 => $this->attribute(6, 'tax_class_id', 'Tax Class', 'select', 0, false),
        ];
        $codes = array_column($attributes, 'code', 'attribute_id');
        $values = [];
        foreach ($codes as $attributeId => $code) {
            $values[$attributeId] = self::VALUES[$code] ?? [];
        }

        $filterAttributes = $this->createStub(FilterAttributes::class);
        $filterAttributes->method('findCategories')->willReturn($categories ?? [['id' => 5, 'name' => 'Cameras']]);
        $filterAttributes->method('categoryProducts')->willReturn(self::PRODUCTS);
        $filterAttributes->method('attributes')->willReturn($attributes);
        $filterAttributes->method('children')->willReturn(self::CHILDREN);
        $filterAttributes->method('values')->willReturn($values);
        $filterAttributes->method('options')->willReturnCallback(
            static fn (array $attribute) => self::OPTIONS[$attribute['code']] ?? []
        );

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getCode')->willReturn('default');
        $store->method('getIsActive')->willReturn(true);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($store);
        $storeManager->method('getStores')->willReturn([$store]);

        return (new AuditFiltersAction($filterAttributes, new ActiveStores($storeManager), $storeManager))
            ->execute($params, 1);
    }

    private function attribute(int $id, string $code, string $label, string $input, int $filterable, bool $own): array
    {
        return [
            'attribute_id' => $id,
            'code' => $code,
            'label' => $label,
            'input' => $input,
            'backend_type' => 'int',
            'is_filterable' => $filterable,
            'is_filterable_in_search' => false,
            'position' => 0,
            'is_user_defined' => $own,
        ];
    }
}

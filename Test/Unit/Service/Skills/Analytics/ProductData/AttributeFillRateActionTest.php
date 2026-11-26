<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Analytics\ProductData;

use MagoAssistant\Mago\Service\Skills\Analytics\ProductData\AttributeFillRateAction;
use MagoAssistant\Mago\Service\Skills\Analytics\ProductData\AttributeFillRateScope;
use MagoAssistant\Mago\Service\Skills\Catalog\CategoryData\ActiveStores;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAttributeFillRateReport;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeStore;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeStoreManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #319: a merchant asked which attributes are populated for the fewest SKUs and Mago could only
 * point to a manual export. attribute_fill_rate ranks attributes by fill rate, least filled first.
 */
final class AttributeFillRateActionTest extends TestCase
{
    /**
     * A small attribute set is not punished for being small: style_bags at 13 of 14 ranks after
     * material at 189 of 2040, even though fewer products have it.
     */
    #[Test]
    public function attributesAreRankedByFillRateNotByCount(): void
    {
        $report = (new FakeAttributeFillRateReport())
            ->withAttribute('style_bags', 13, 14)
            ->withAttribute('material', 189, 2040)
            ->withAttribute('color', 2040, 2040);

        $result = $this->action($report)->execute(['action' => 'attribute_fill_rate'], 1);

        self::assertSame(['material', 'style_bags', 'color'], array_column($result['attributes'], 'attribute_code'));
        self::assertSame(9.3, $result['attributes'][0]['fill_rate']);
    }

    #[Test]
    public function equalRatesAreOrderedByAttributeCode(): void
    {
        $report = (new FakeAttributeFillRateReport())
            ->withAttribute('sleeve', 0, 1462)
            ->withAttribute('collar', 0, 1462);

        $result = $this->action($report)->execute(['action' => 'attribute_fill_rate'], 1);

        self::assertSame(['collar', 'sleeve'], array_column($result['attributes'], 'attribute_code'));
    }

    #[Test]
    public function rowsAreCutAtTheLimitAndTheResultSaysSo(): void
    {
        $report = (new FakeAttributeFillRateReport())
            ->withAttribute('a', 1, 10)
            ->withAttribute('b', 2, 10)
            ->withAttribute('c', 3, 10);

        $result = $this->action($report)->execute(['action' => 'attribute_fill_rate', 'limit' => 2], 1);

        self::assertSame(['a', 'b'], array_column($result['attributes'], 'attribute_code'));
        self::assertSame(2, $result['shown']);
        self::assertSame(3, $result['attribute_count']);
        self::assertTrue($result['is_partial']);
    }

    #[Test]
    public function withoutAStoreTheDefaultScopeOfEveryProductIsRead(): void
    {
        $report = new FakeAttributeFillRateReport();

        $result = $this->action($report)->execute(['action' => 'attribute_fill_rate'], 1);

        self::assertSame('default', $result['scope']);
        self::assertSame('', $result['store_code']);
        self::assertEquals(new AttributeFillRateScope(), $report->scopes[0]);
    }

    #[Test]
    public function aStoreViewIsReadWithinItsWebsite(): void
    {
        $report = new FakeAttributeFillRateReport();

        $result = $this->action($report)->execute(['action' => 'attribute_fill_rate', 'store' => 'NL'], 1);

        self::assertSame('store_view', $result['scope']);
        self::assertSame('nl', $result['store_code']);
        self::assertSame(3, $report->scopes[0]->storeId);
        self::assertSame(2, $report->scopes[0]->websiteId);
    }

    #[Test]
    public function anUnknownStoreViewIsAnError(): void
    {
        $report = new FakeAttributeFillRateReport();

        $result = $this->action($report)->execute(['action' => 'attribute_fill_rate', 'store' => 'closed'], 1);

        self::assertSame(['error' => 'Unknown store view: closed'], $result);
        self::assertSame([], $report->scopes);
    }

    /**
     * @return array<string,array{0:string,1:int}>
     */
    public static function attributeSetInputs(): array
    {
        return [
            'name' => ['Bag', 15],
            'name in another case' => ['bag', 15],
            'id' => ['15', 15],
        ];
    }

    #[Test]
    #[DataProvider('attributeSetInputs')]
    public function theAttributeSetIsFoundByNameOrId(string $input, int $expectedId): void
    {
        $report = new FakeAttributeFillRateReport([4 => 'Default', 15 => 'Bag']);

        $result = $this->action($report)->execute(['action' => 'attribute_fill_rate', 'attribute_set' => $input], 1);

        self::assertSame('Bag', $result['attribute_set']);
        self::assertSame($expectedId, $report->scopes[0]->attributeSetId);
    }

    #[Test]
    public function anUnknownAttributeSetListsTheSetsThatExist(): void
    {
        $report = new FakeAttributeFillRateReport([15 => 'Bag', 4 => 'Default']);

        $result = $this->action($report)->execute(['action' => 'attribute_fill_rate', 'attribute_set' => 'Shoes'], 1);

        self::assertSame(['error' => 'Unknown attribute set: Shoes. Attribute sets: Bag, Default'], $result);
    }

    /**
     * @return array<string,array{0:array<string,mixed>,1:bool,2:bool}>
     */
    public static function flagInputs(): array
    {
        return [
            'omitted means off' => [[], false, false],
            'true turns them on' => [['only_enabled' => true, 'include_system' => true], true, true],
            'the string "true" too' => [['only_enabled' => 'true', 'include_system' => 'true'], true, true],
            'garbage keeps the default' => [['only_enabled' => 'maybe', 'include_system' => 'maybe'], false, false],
        ];
    }

    #[Test]
    #[DataProvider('flagInputs')]
    public function onlyEnabledAndIncludeSystemAreOffUnlessTurnedOn(array $params, bool $onlyEnabled, bool $includeSystem): void
    {
        $report = new FakeAttributeFillRateReport();

        $result = $this->action($report)->execute(['action' => 'attribute_fill_rate'] + $params, 1);

        self::assertSame($onlyEnabled, $result['only_enabled']);
        self::assertSame($includeSystem, $result['include_system']);
        self::assertSame($onlyEnabled, $report->scopes[0]->isOnlyEnabled);
        self::assertSame($includeSystem, $report->scopes[0]->isIncludingSystem);
    }

    #[Test]
    public function attributesNoProductCanHaveAreCountedNotListed(): void
    {
        $report = (new FakeAttributeFillRateReport())->withAttribute('color', 5, 10)->withAttributesWithoutProducts(2);

        $result = $this->action($report)->execute(['action' => 'attribute_fill_rate'], 1);

        self::assertSame(2, $result['attributes_without_products']);
        self::assertSame(1, $result['attribute_count']);
    }

    #[Test]
    public function theExportHoldsEveryAttributeLeastFilledFirst(): void
    {
        $report = (new FakeAttributeFillRateReport())
            ->withAttribute('b', 2, 10)
            ->withAttribute('a', 1, 10)
            ->withAttribute('c', 3, 10);
        $action = $this->action($report);

        $rows = iterator_to_array($action->exportRows(['action' => 'attribute_fill_rate', 'limit' => 1], 1, 100), false);

        self::assertSame(['a', 'b', 'c'], array_column($rows, 'attribute_code'));
        self::assertSame(3, $action->countExportRows(['action' => 'attribute_fill_rate'], 1));
        self::assertSame(
            [],
            array_diff(array_map(static fn ($column): string => $column->key, $action->getExportColumns()), array_keys($rows[0]))
        );
    }

    #[Test]
    public function theExportStopsAtTheRowsAskedFor(): void
    {
        $report = (new FakeAttributeFillRateReport())->withAttribute('a', 1, 10)->withAttribute('b', 2, 10);

        $rows = iterator_to_array($this->action($report)->exportRows(['action' => 'attribute_fill_rate'], 1, 1), false);

        self::assertSame(['a'], array_column($rows, 'attribute_code'));
    }

    #[Test]
    public function readingFillRatesNeedsTheProductsAcl(): void
    {
        self::assertSame('Magento_Catalog::products', $this->action(new FakeAttributeFillRateReport())->getAclResource());
    }

    #[Test]
    public function everyResultFieldIsClassified(): void
    {
        $action = $this->action((new FakeAttributeFillRateReport())->withAttribute('color', 5, 10));

        $result = $action->execute(['action' => 'attribute_fill_rate', 'store' => 'nl'], 1);

        $keys = array_merge(array_keys($result), array_keys($result['attributes'][0]));
        self::assertSame([], array_diff($keys, array_keys($action->getFieldClassification()), ['attributes']));
    }

    private function action(FakeAttributeFillRateReport $report): AttributeFillRateAction
    {
        $storeManager = new FakeStoreManager(
            new FakeStore(1, 'default', 'Default', 1),
            new FakeStore(3, 'nl', 'Dutch', 2),
            new FakeStore(4, 'closed', 'Closed', 1, 1, false)
        );

        return new AttributeFillRateAction($report, new ActiveStores($storeManager));
    }
}

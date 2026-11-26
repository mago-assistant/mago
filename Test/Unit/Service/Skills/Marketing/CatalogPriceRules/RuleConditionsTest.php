<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Marketing\CatalogPriceRules;

use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\InvalidRuleConditionException;
use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\PromoAttribute;
use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\RuleCondition;
use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\RuleConditions;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeBackendUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakePromoAttributes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #329: catalog price rule conditions on attribute values, SKUs and categories. Attributes and
 * options are found by the names the administrator uses; anything that would not save as asked is
 * refused with a message the model can act on.
 */
final class RuleConditionsTest extends TestCase
{
    private RuleConditions $ruleConditions;

    protected function setUp(): void
    {
        $this->ruleConditions = new RuleConditions(
            new FakePromoAttributes(
                new PromoAttribute(93, 'color', 'Color', 'select', true, ['49' => 'Red', '50' => 'Blue']),
                new PromoAttribute(140, 'resolution', 'Resolution', 'select', true, ['3' => '8MP', '4' => '4MP']),
                new PromoAttribute(141, 'camera_type', 'Camera type', 'select', true, ['7' => 'Turret', '8' => 'Dome']),
                new PromoAttribute(142, 'features', 'Features', 'multiselect', true, ['11' => 'Night vision', '12' => 'Wifi']),
                new PromoAttribute(143, 'is_outdoor', 'Outdoor', 'boolean', true),
                new PromoAttribute(144, 'finish', 'Finish', 'select', true, ['1' => 'Matte', '2' => 'matte']),
                new PromoAttribute(145, 'size_cm', 'Size', 'select', true, ['1' => 'S']),
                new PromoAttribute(146, 'size_eu', 'Size', 'select', true, ['2' => 'S']),
                new PromoAttribute(147, 'news_from_date', 'New from', 'date', true),
                new PromoAttribute(83, 'manufacturer', 'Manufacturer', 'select', false, ['5' => 'Acme']),
                new PromoAttribute(74, 'sku', 'SKU', 'text', true)
            ),
            new SecureAdminUrl(new FakeBackendUrl())
        );
    }

    #[Test]
    public function itHasNoConditionsWhenNeitherConditionsNorCategoriesAreGiven(): void
    {
        $hasConditions = $this->ruleConditions->hasConditions(['name' => 'Spring sale']);

        self::assertFalse($hasConditions);
    }

    #[Test]
    public function itBuildsTheIssueExampleFromOptionLabels(): void
    {
        $conditions = $this->build([
            ['attribute' => 'Resolution', 'values' => ['8mp']],
            ['attribute' => 'camera type', 'operator' => 'is', 'values' => ['turret']],
        ]);

        self::assertEquals(
            [
                new RuleCondition('resolution', '==', '3', 'Resolution is 8MP'),
                new RuleCondition('camera_type', '==', '7', 'Camera type is Turret'),
            ],
            $conditions
        );
    }

    #[Test]
    public function severalValuesMeanIsOneOf(): void
    {
        $conditions = $this->build([['attribute' => 'color', 'values' => ['Red', 'Blue']]]);

        self::assertEquals([new RuleCondition('color', '()', '49,50', 'Color is one of Red, Blue')], $conditions);
    }

    #[Test]
    public function isNotWithSeveralValuesMeansIsNotOneOf(): void
    {
        $conditions = $this->build([['attribute' => 'sku', 'operator' => 'is_not', 'values' => ['CAM-1', 'CAM-2']]]);

        self::assertEquals([new RuleCondition('sku', '!()', 'CAM-1,CAM-2', 'SKU is not one of CAM-1, CAM-2')], $conditions);
    }

    #[Test]
    public function aMultiselectValueIsMatchedAsAList(): void
    {
        $conditions = $this->build([['attribute' => 'features', 'values' => ['Wifi']]]);

        self::assertSame('()', $conditions[0]->operator);
        self::assertSame('12', $conditions[0]->value);
    }

    #[Test]
    public function aYesNoAttributeTakesYesOrNo(): void
    {
        $conditions = $this->build([['attribute' => 'Outdoor', 'operator' => 'is not', 'values' => ['Yes']]]);

        self::assertEquals([new RuleCondition('is_outdoor', '!=', '1', 'Outdoor is not Yes')], $conditions);
    }

    #[Test]
    public function anOptionIdIsAcceptedWhenNoLabelMatches(): void
    {
        $conditions = $this->build([['attribute' => 'color', 'values' => ['50']]]);

        self::assertSame('50', $conditions[0]->value);
    }

    #[Test]
    public function aCategoryConditionTakesCategoryIds(): void
    {
        $conditions = $this->build([['attribute' => 'category', 'operator' => 'is_not', 'values' => ['12', '14']]]);

        self::assertEquals(
            [new RuleCondition('category_ids', '!()', '12,14', 'the category is not one of 12, 14')],
            $conditions
        );
    }

    #[Test]
    public function conditionsPassedAsAJsonStringAreRead(): void
    {
        $conditions = $this->ruleConditions->build(['conditions' => '[{"attribute":"color","values":["Red"]}]']);

        self::assertSame('49', $conditions->conditions[0]->value);
    }

    /**
     * Read as no conditions, a malformed string would save the rule for the whole catalog
     */
    #[Test]
    public function conditionsThatAreNotValidJsonAreRefused(): void
    {
        $this->expectException(InvalidRuleConditionException::class);
        $this->expectExceptionMessage('conditions could not be read');

        $this->ruleConditions->build(['conditions' => '[{attribute: color, values: [Red]}]']);
    }

    #[Test]
    public function categoryIdsBecomeTheRequiredCategoryCondition(): void
    {
        $conditions = $this->ruleConditions->build(['category_ids' => [5]]);

        self::assertEquals(new RuleCondition('category_ids', '()', '5', 'the category is one of 5'), $conditions->categoryCondition);
        self::assertSame([], $conditions->conditions);
    }

    #[Test]
    public function anUnknownAttributeIsRefused(): void
    {
        $this->assertRefused([['attribute' => 'megapixels', 'values' => ['8']]], 'No product attribute has the code or label "megapixels"');
    }

    #[Test]
    public function anAmbiguousAttributeLabelIsRefusedWithTheCandidates(): void
    {
        $this->assertRefused([['attribute' => 'Size', 'values' => ['S']]], 'size_cm (Size), size_eu (Size)');
    }

    #[Test]
    public function anAttributeNotUsedForPromoRulesIsRefusedWithWhereToEnableIt(): void
    {
        $this->assertRefused(
            [['attribute' => 'manufacturer', 'values' => ['Acme']]],
            '"Use for Promo Rule Conditions" is set to Yes on its Storefront Properties tab: '
                . 'https://shop.test/admin/catalog/product_attribute/edit/'
        );
    }

    #[Test]
    public function anUnknownOptionIsRefusedWithTheOptions(): void
    {
        $this->assertRefused([['attribute' => 'color', 'values' => ['Purple']]], 'Its options are: Red, Blue.');
    }

    #[Test]
    public function anAmbiguousOptionLabelIsRefusedWithTheOptionIds(): void
    {
        $this->assertRefused([['attribute' => 'finish', 'values' => ['MATTE']]], 'more than one option labelled "MATTE" (option IDs 1, 2)');
    }

    #[Test]
    public function aYesNoAttributeRefusesOtherValues(): void
    {
        $this->assertRefused([['attribute' => 'is_outdoor', 'values' => ['sometimes']]], 'is a yes/no attribute');
    }

    #[Test]
    public function aDateAttributeIsRefused(): void
    {
        $this->assertRefused([['attribute' => 'news_from_date', 'values' => ['2026-01-01']]], 'are not supported here');
    }

    #[Test]
    public function aCategoryNameIsRefusedAsACategoryId(): void
    {
        $this->assertRefused([['attribute' => 'category', 'values' => ['Cameras']]], 'look up the ID of: Cameras');
    }

    #[Test]
    public function anOperatorOtherThanIsOrIsNotIsRefused(): void
    {
        $this->assertRefused([['attribute' => 'color', 'operator' => 'contains', 'values' => ['Red']]], '"contains" is not supported');
    }

    #[Test]
    public function aConditionWithoutValuesIsRefused(): void
    {
        $this->assertRefused([['attribute' => 'color', 'values' => []]], 'needs at least one value');
    }

    /**
     * Magento splits a list value on commas and semicolons
     */
    #[Test]
    public function aListValueHoldingACommaIsRefused(): void
    {
        $this->assertRefused([['attribute' => 'sku', 'values' => ['CAM-1', 'CAM,2']]], 'cannot contain a comma or semicolon');
    }

    #[Test]
    public function moreThanTenConditionsAreRefused(): void
    {
        $this->assertRefused(array_fill(0, 11, ['attribute' => 'color', 'values' => ['Red']]), 'at most 10 conditions');
    }

    #[Test]
    public function aMatchOtherThanAllOrAnyIsRefused(): void
    {
        $this->expectException(InvalidRuleConditionException::class);
        $this->expectExceptionMessage('conditions_match must be "all" or "any"');

        $this->ruleConditions->build(['conditions' => [['attribute' => 'color', 'values' => ['Red']]], 'conditions_match' => 'most']);
    }

    /**
     * @param array<int, array<string, mixed>> $conditions
     * @return RuleCondition[]
     */
    private function build(array $conditions): array
    {
        return $this->ruleConditions->build(['conditions' => $conditions])->conditions;
    }

    /**
     * @param array<int, array<string, mixed>> $conditions
     */
    private function assertRefused(array $conditions, string $expectedMessage): void
    {
        $this->expectException(InvalidRuleConditionException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->build($conditions);
    }
}

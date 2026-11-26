<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Marketing\CouponManager;

use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleConditionBuilder;
use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleConditionReader;
use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleConditionVocabulary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #328: what RuleConditionBuilder writes, RuleConditionReader reads back unchanged, and a
 * tree it cannot express is reported as unreadable instead of being half read.
 */
final class RuleConditionTreeTest extends TestCase
{
    private const ADDRESS = RuleConditionVocabulary::ADDRESS_CLASS;
    private const COMBINE = RuleConditionVocabulary::COMBINE_CLASS;
    private const PRODUCT = RuleConditionVocabulary::PRODUCT_CLASS;

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function conditionTrees(): array
    {
        return [
            'no conditions' => [['match' => 'all', 'rules' => []]],
            'cart totals' => [['match' => 'any', 'rules' => [
                ['field' => 'subtotal', 'operator' => '>=', 'value' => 100.0],
                ['field' => 'total_qty', 'operator' => '<', 'value' => 5.0],
                ['field' => 'subtotal_incl_tax', 'operator' => '>', 'value' => 50.5],
            ]]],
            'one country' => [['match' => 'all', 'rules' => [
                ['field' => 'shipping_country', 'operator' => 'not_in', 'value' => ['NL']],
            ]]],
            'several countries' => [['match' => 'all', 'rules' => [
                ['field' => 'shipping_country', 'operator' => 'in', 'value' => ['NL', 'BE']],
                ['field' => 'shipping_country', 'operator' => 'not_in', 'value' => ['DE', 'FR']],
            ]]],
            'product found and not found' => [['match' => 'all', 'rules' => [
                ['field' => 'product_in_cart', 'presence' => 'found', 'match' => 'any', 'rules' => [
                    ['attribute' => 'category_ids', 'operator' => 'in', 'value' => ['16', '25']],
                    ['attribute' => 'sku', 'operator' => 'is', 'value' => 'MS04'],
                ]],
                ['field' => 'product_in_cart', 'presence' => 'not_found', 'match' => 'all', 'rules' => [
                    ['attribute' => 'brand', 'operator' => 'is_not', 'value' => '5437'],
                ]],
            ]]],
        ];
    }

    #[Test]
    #[DataProvider('conditionTrees')]
    public function conditionsReadBackAsTheyWereWritten(array $conditions): void
    {
        $rest = (new RuleConditionBuilder())->buildConditions($conditions);

        self::assertSame($conditions, (new RuleConditionReader())->readConditions($rest));
    }

    #[Test]
    public function actionsReadBackAsTheyWereWritten(): void
    {
        $actions = ['match' => 'all', 'rules' => [
            ['attribute' => 'category_ids', 'operator' => 'not_in', 'value' => ['31']],
            ['attribute' => 'activity', 'operator' => 'in', 'value' => ['8', '9']],
        ]];

        $rest = (new RuleConditionBuilder())->buildActions($actions);

        self::assertSame($actions, (new RuleConditionReader())->readActions($rest));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function actionTreesWithGroups(): array
    {
        return [
            'sample rule 3: every item except category 6 or on sale' => [['match' => 'all', 'rules' => [
                ['group' => ['match' => 'all', 'is' => false, 'rules' => [
                    ['attribute' => 'category_ids', 'operator' => 'is', 'value' => '6'],
                    ['attribute' => 'sale', 'operator' => 'is', 'value' => '1'],
                ]]],
            ]]],
            'negated root' => [['match' => 'any', 'is' => false, 'rules' => [
                ['attribute' => 'sku', 'operator' => 'in', 'value' => ['MS04', 'MS05']],
            ]]],
            'group next to a rule' => [['match' => 'all', 'rules' => [
                ['attribute' => 'brand', 'operator' => 'is', 'value' => '5437'],
                ['group' => ['match' => 'any', 'rules' => [
                    ['attribute' => 'category_ids', 'operator' => 'in', 'value' => ['16']],
                    ['attribute' => 'sku', 'operator' => 'is', 'value' => 'MS04'],
                ]]],
            ]]],
        ];
    }

    #[Test]
    #[DataProvider('actionTreesWithGroups')]
    public function groupsAndNegationReadBackAsTheyWereWritten(array $actions): void
    {
        $rest = (new RuleConditionBuilder())->buildActions($actions);

        self::assertSame($actions, (new RuleConditionReader())->readActions($rest));
    }

    #[Test]
    public function aGroupThatHasToFailIsWrittenWithValueZero(): void
    {
        $rest = (new RuleConditionBuilder())->buildActions(['match' => 'all', 'rules' => [
            ['group' => ['match' => 'all', 'is' => false, 'rules' => [
                ['attribute' => 'sku', 'operator' => 'is', 'value' => 'MS04'],
            ]]],
        ]]);

        self::assertSame('1', $rest['value']);
        self::assertSame(RuleConditionVocabulary::PRODUCT_COMBINE_CLASS, $rest['conditions'][0]['condition_type']);
        self::assertSame('0', $rest['conditions'][0]['value']);
    }

    #[Test]
    public function groupsInsideAProductInCartConditionReadBack(): void
    {
        $conditions = ['match' => 'all', 'is' => false, 'rules' => [
            ['field' => 'product_in_cart', 'presence' => 'found', 'match' => 'all', 'rules' => [
                ['group' => ['match' => 'any', 'is' => false, 'rules' => [
                    ['attribute' => 'sku', 'operator' => 'is', 'value' => 'MS04'],
                ]]],
            ]],
        ]];

        $rest = (new RuleConditionBuilder())->buildConditions($conditions);

        self::assertSame($conditions, (new RuleConditionReader())->readConditions($rest));
    }

    #[Test]
    public function sampleRuleThreeAsMagentoStoresItIsReadable(): void
    {
        $actions = [
            'condition_type' => RuleConditionVocabulary::PRODUCT_COMBINE_CLASS,
            'aggregator_type' => 'all',
            'value' => '1',
            'conditions' => [[
                'condition_type' => RuleConditionVocabulary::PRODUCT_COMBINE_CLASS,
                'aggregator_type' => 'all',
                'value' => '0',
                'conditions' => [
                    ['condition_type' => self::PRODUCT, 'attribute_name' => 'category_ids', 'operator' => '==', 'value' => '6'],
                    ['condition_type' => self::PRODUCT, 'attribute_name' => 'sale', 'operator' => '==', 'value' => '1'],
                ],
            ]],
        ];

        $read = (new RuleConditionReader())->readActions($actions);

        self::assertFalse($read['rules'][0]['group']['is']);
        self::assertSame('category_ids', $read['rules'][0]['group']['rules'][0]['attribute']);
    }

    #[Test]
    public function severalCountriesBecomeANestedCombinationOfSingleCountries(): void
    {
        $rest = (new RuleConditionBuilder())->buildConditions(['match' => 'all', 'rules' => [
            ['field' => 'shipping_country', 'operator' => 'in', 'value' => ['nl', 'BE']],
        ]]);

        $nested = $rest['conditions'][0];
        self::assertSame(self::COMBINE, $nested['condition_type']);
        self::assertSame('any', $nested['aggregator_type']);
        self::assertSame(['NL', 'BE'], array_column($nested['conditions'], 'value'));
        self::assertSame(['==', '=='], array_column($nested['conditions'], 'operator'));
    }

    #[Test]
    public function theMagentoClassesNeverComeFromInput(): void
    {
        $rest = (new RuleConditionBuilder())->buildConditions([
            'match' => 'all',
            'condition_type' => 'Magento\\Framework\\DataObject',
            'rules' => [['field' => 'subtotal', 'operator' => '>=', 'value' => 1, 'condition_type' => 'Evil']],
        ]);

        self::assertSame(self::COMBINE, $rest['condition_type']);
        self::assertSame(self::ADDRESS, $rest['conditions'][0]['condition_type']);
    }

    #[Test]
    public function aMissingTreeReadsAsEmpty(): void
    {
        self::assertSame(['match' => 'all', 'rules' => []], (new RuleConditionReader())->readConditions(null));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function unreadableConditions(): array
    {
        $root = static fn (array $child): array => [
            'condition_type' => self::COMBINE,
            'aggregator_type' => 'all',
            'value' => '1',
            'conditions' => [$child],
        ];

        return [
            'payment method' => [$root([
                'condition_type' => self::ADDRESS,
                'attribute_name' => 'payment_method',
                'operator' => '==',
                'value' => 'checkmo',
            ])],
            'subselect' => [$root([
                'condition_type' => 'Magento\\SalesRule\\Model\\Rule\\Condition\\Product\\Subselect',
                'attribute_name' => 'qty',
                'operator' => '>=',
                'value' => '2',
            ])],
            'root with an unknown match' => [['condition_type' => self::COMBINE, 'aggregator_type' => 'most', 'value' => '1']],
            'third party condition' => [$root(['condition_type' => 'Vendor\\Module\\Condition', 'value' => '1'])],
            'scoped product attribute' => [$root([
                'condition_type' => RuleConditionVocabulary::PRODUCT_FOUND_CLASS,
                'aggregator_type' => 'all',
                'value' => '1',
                'conditions' => [[
                    'condition_type' => self::PRODUCT,
                    'attribute_name' => 'sku',
                    'operator' => '==',
                    'value' => 'MS04',
                    'extension_attributes' => ['attribute_scope' => 'parent'],
                ]],
            ])],
            'nested combination that is not a country list' => [$root([
                'condition_type' => self::COMBINE,
                'aggregator_type' => 'any',
                'value' => '1',
                'conditions' => [[
                    'condition_type' => self::ADDRESS,
                    'attribute_name' => 'base_subtotal',
                    'operator' => '>=',
                    'value' => '10',
                ]],
            ])],
        ];
    }

    #[Test]
    #[DataProvider('unreadableConditions')]
    public function aTreeWithSomethingMagoCannotSayIsUnreadable(array $condition): void
    {
        self::assertNull((new RuleConditionReader())->readConditions($condition));
    }

    #[Test]
    public function aGroupInsideAGroupIsUnreadable(): void
    {
        $group = static fn (array $conditions): array => [
            'condition_type' => RuleConditionVocabulary::PRODUCT_COMBINE_CLASS,
            'aggregator_type' => 'all',
            'value' => '0',
            'conditions' => $conditions,
        ];
        $actions = [
            'condition_type' => RuleConditionVocabulary::PRODUCT_COMBINE_CLASS,
            'aggregator_type' => 'all',
            'value' => '1',
            'conditions' => [$group([$group([])])],
        ];

        self::assertNull((new RuleConditionReader())->readActions($actions));
    }
}

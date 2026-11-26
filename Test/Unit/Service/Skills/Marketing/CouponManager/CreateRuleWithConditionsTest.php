<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Marketing\CouponManager;

use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\CreateRuleAction;
use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleConditionVocabulary;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeInternalApiClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #328: create_rule takes conditions, actions, a maximum quantity, a step and buy X get Y.
 */
final class CreateRuleWithConditionsTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    private const CONDITIONS = [
        'match' => 'all',
        'rules' => [
            ['field' => 'subtotal', 'operator' => '>=', 'value' => 100],
            ['field' => 'product_in_cart', 'presence' => 'found', 'match' => 'all', 'rules' => [
                ['attribute' => 'category_ids', 'operator' => 'in', 'value' => [RuleTargetFixture::WOMEN_TEES]],
            ]],
        ],
    ];

    private const ACTIONS = [
        'match' => 'all',
        'rules' => [['attribute' => 'brand', 'operator' => 'is', 'value' => RuleTargetFixture::HIKVISION]],
    ];

    private FakeInternalApiClient $apiClient;

    protected function setUp(): void
    {
        $this->apiClient = RuleTargetFixture::store()
            ->withResponse(FakeInternalApiClient::POST, 'salesRules', ['rule_id' => 12]);
    }

    #[Test]
    public function itSavesTheConditionsAndActionsAsMagentoTrees(): void
    {
        $result = $this->create(['conditions' => self::CONDITIONS, 'actions' => self::ACTIONS]);

        $rule = $this->savedRule();
        self::assertTrue($result['success'] ?? false);
        self::assertSame(RuleConditionVocabulary::COMBINE_CLASS, $rule['condition']['condition_type']);
        self::assertSame('base_subtotal', $rule['condition']['conditions'][0]['attribute_name']);
        self::assertSame('25', $rule['condition']['conditions'][1]['conditions'][0]['value']);
        self::assertSame(RuleConditionVocabulary::PRODUCT_COMBINE_CLASS, $rule['action_condition']['condition_type']);
        self::assertSame('5437', $rule['action_condition']['conditions'][0]['value']);
    }

    #[Test]
    public function itAcceptsConditionsSentAsAJsonString(): void
    {
        $this->create(['conditions' => json_encode(self::CONDITIONS)]);

        self::assertSame('base_subtotal', $this->savedRule()['condition']['conditions'][0]['attribute_name']);
    }

    #[Test]
    public function aRuleWithoutConditionsSendsNoTrees(): void
    {
        $this->create([]);

        self::assertArrayNotHasKey('condition', $this->savedRule());
        self::assertArrayNotHasKey('action_condition', $this->savedRule());
    }

    #[Test]
    public function itSavesTheMaximumQuantityAndStep(): void
    {
        $this->create(['discount_qty' => 3, 'discount_step' => 2]);

        self::assertSame(3, $this->savedRule()['discount_qty']);
        self::assertSame(2, $this->savedRule()['discount_step']);
    }

    #[Test]
    public function buyThreePayTwoIsSavedAsBuyXGetY(): void
    {
        $this->create(['discount_type' => 'buy_x_get_y', 'discount_step' => 2, 'discount_amount' => 1]);

        self::assertSame('buy_x_get_y', $this->savedRule()['simple_action']);
        self::assertSame(2, $this->savedRule()['discount_step']);
        self::assertSame(1.0, $this->savedRule()['discount_amount']);
    }

    #[Test]
    public function buyXGetYWithoutAStepIsRefusedBeforeTheCard(): void
    {
        $refusal = $this->action()->findRefusal(['name' => 'B2G1', 'discount_type' => 'buy_x_get_y', 'discount_amount' => 1]);

        self::assertStringContainsString('buy_x_get_y needs discount_step', $refusal['error'] ?? '');
    }

    #[Test]
    public function moreFreeThanBoughtIsRefusedBecauseMagentoNeverAppliesIt(): void
    {
        $refusal = $this->action()->findRefusal([
            'name' => 'B1G2',
            'discount_type' => 'buy_x_get_y',
            'discount_step' => 1,
            'discount_amount' => 2,
        ]);

        self::assertStringStartsWith('buy_x_get_y cannot give more items free', $refusal['error'] ?? '');
    }

    #[Test]
    public function aMalformedTreeIsRefusedBeforeTheCard(): void
    {
        $refusal = $this->action()->findRefusal([
            'name' => 'Bad',
            'conditions' => ['match' => 'all', 'rules' => [['field' => 'payment_method', 'value' => 'checkmo']]],
        ]);

        self::assertStringContainsString('Unsupported condition field "payment_method"', $refusal['error'] ?? '');
    }

    #[Test]
    public function anAttributeNotUsedForPromoRulesIsRefusedWithTheSettingToChange(): void
    {
        $result = $this->create(['actions' => [
            'match' => 'all',
            'rules' => [['attribute' => 'description', 'operator' => 'is', 'value' => 'x']],
        ]]);

        self::assertStringContainsString('is not used for promo rules', $result['error'] ?? '');
        self::assertStringContainsString('Use for Promo Rule Conditions', $result['error'] ?? '');
        self::assertSame([], $this->apiClient->callsOf(FakeInternalApiClient::POST));
    }

    #[Test]
    public function aCategoryThatDoesNotExistIsRefused(): void
    {
        $result = $this->create(['actions' => [
            'match' => 'all',
            'rules' => [['attribute' => 'category_ids', 'operator' => 'in', 'value' => [999]]],
        ]]);

        self::assertSame(
            'No category with id 999 exists, or this admin role cannot read categories. Look categories up with find_rule_targets.',
            $result['error'] ?? null
        );
    }

    #[Test]
    public function theCardNoLongerSaysEveryCartWhenConditionsNarrowItDown(): void
    {
        $cautions = $this->cautions(['conditions' => self::CONDITIONS]);

        self::assertSame('Takes 15% off carts that meet the conditions, automatically and without a coupon.', $cautions[0]);
    }

    #[Test]
    public function theCardSpellsOutTheConditionsAndActionsByName(): void
    {
        $cautions = $this->cautions(['conditions' => self::CONDITIONS, 'actions' => self::ACTIONS]);

        self::assertContains(
            'Applies when ALL of these hold: the subtotal (base currency) is at least 100; the cart has an item '
            . 'where category is Default Category > Women > Tees (25).',
            $cautions
        );
        self::assertContains('Discounts only items where Brand is Hikvision.', $cautions);
    }

    /**
     * The model may pick a category id without looking the name up or asking, so the card names
     * the other categories with the same name.
     */
    #[Test]
    public function theCardWarnsWhenTheChosenCategorySharesItsName(): void
    {
        $cautions = $this->cautions(['conditions' => self::CONDITIONS]);

        self::assertContains(
            'Check the category: 2 categories are named "Tees". This rule uses Default Category > Women > Tees '
            . '(25), not Default Category > Men > Tees (16).',
            $cautions
        );
    }

    #[Test]
    public function theCardHasNoCategoryWarningWithoutCategories(): void
    {
        $cautions = $this->cautions(['actions' => self::ACTIONS]);

        self::assertSame([], array_filter($cautions, static fn (string $line): bool => str_starts_with($line, 'Check the category')));
    }

    #[Test]
    public function theCardNamesTheMaximumQuantity(): void
    {
        self::assertContains('Discounts at most 2 item(s) per cart.', $this->cautions(['discount_qty' => 2]));
    }

    #[Test]
    public function theCardExplainsBuyXGetY(): void
    {
        $cautions = $this->cautions(['discount_type' => 'buy_x_get_y', 'discount_step' => 2, 'discount_amount' => 1]);

        self::assertSame(
            'Gives 1 item(s) free for every 2 bought (buy 2, get 1 free) in every cart, automatically and without a coupon.',
            $cautions[0]
        );
    }

    #[Test]
    public function theSchemaSaysThereIsNoCheapestItemDiscount(): void
    {
        $description = $this->action()->getParameterSchema()['discount_type']['description'];

        self::assertStringContainsString('no "cheapest item" discount', $description);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function create(array $params): array
    {
        return $this->action()->execute($params + ['name' => 'Spring sale'], self::ADMIN_USER_ID);
    }

    /**
     * @param array<string, mixed> $params
     * @return string[]
     */
    private function cautions(array $params): array
    {
        return $this->action()->getCautions(
            $params + ['name' => 'Spring sale', 'discount_type' => 'percent', 'discount_amount' => 15],
            self::ADMIN_USER_ID
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function savedRule(): array
    {
        return $this->apiClient->callsOf(FakeInternalApiClient::POST)[0]['payload']['rule'];
    }

    private function action(): CreateRuleAction
    {
        return RuleTargetFixture::createAction($this->apiClient);
    }
}

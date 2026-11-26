<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Marketing\CouponManager;

use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleConditionBuilder;
use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\UpdateRuleAction;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeInternalApiClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #328: update_rule changes conditions, actions and discount settings of an existing rule,
 * keeps what it is not given, and leaves alone a tree it cannot read.
 */
final class UpdateRuleActionTest extends TestCase
{
    private const ADMIN_USER_ID = 7;
    private const RULE_ID = 12;

    private FakeInternalApiClient $apiClient;

    protected function setUp(): void
    {
        $this->apiClient = RuleTargetFixture::store()
            ->withResponse(FakeInternalApiClient::GET, 'salesRules/' . self::RULE_ID, $this->openBoxRule())
            ->withResponse(FakeInternalApiClient::PUT, 'salesRules/' . self::RULE_ID, ['rule_id' => self::RULE_ID]);
    }

    #[Test]
    public function itReplacesTheConditionsAndKeepsEverythingElse(): void
    {
        $result = $this->update(['conditions' => [
            'match' => 'all',
            'rules' => [['field' => 'subtotal', 'operator' => '>=', 'value' => 50]],
        ]]);

        $saved = $this->savedRule();
        self::assertTrue($result['success'] ?? false);
        self::assertSame(['conditions'], $result['changed']);
        self::assertSame('base_subtotal', $saved['condition']['conditions'][0]['attribute_name']);
        self::assertSame($this->openBoxRule()['action_condition'], $saved['action_condition']);
        self::assertSame('Open box deals', $saved['name']);
        self::assertSame(10, $saved['discount_amount']);
        self::assertSame([1], $saved['website_ids']);
    }

    #[Test]
    public function anEmptyRuleListClearsTheActions(): void
    {
        $this->update(['actions' => ['match' => 'all', 'rules' => []]]);

        self::assertSame([], $this->savedRule()['action_condition']['conditions']);
    }

    #[Test]
    public function itChangesTheDiscountSettings(): void
    {
        $this->update(['discount_amount' => 20, 'discount_qty' => 4, 'free_shipping' => true, 'to_date' => '2030-01-01']);

        $saved = $this->savedRule();
        self::assertSame(20.0, $saved['discount_amount']);
        self::assertSame(4, $saved['discount_qty']);
        self::assertSame('1', $saved['simple_free_shipping']);
        self::assertSame('2030-01-01', $saved['to_date']);
    }

    #[Test]
    public function anEmptyDateIsSentAsAnEmptyStringBecauseRestKeepsTheDateOnNull(): void
    {
        $this->update(['from_date' => '']);

        self::assertSame('', $this->savedRule()['from_date']);
    }

    #[Test]
    public function itRefusesToReplaceATreeItCannotRead(): void
    {
        $this->apiClient->withResponse(FakeInternalApiClient::GET, 'salesRules/' . self::RULE_ID, [
            ...$this->openBoxRule(),
            'condition' => [
                'condition_type' => 'Magento\\SalesRule\\Model\\Rule\\Condition\\Combine',
                'aggregator_type' => 'all',
                'value' => '1',
                'conditions' => [[
                    'condition_type' => 'Magento\\SalesRule\\Model\\Rule\\Condition\\Address',
                    'attribute_name' => 'payment_method',
                    'operator' => '==',
                    'value' => 'checkmo',
                ]],
            ],
        ]);

        $result = $this->update(['conditions' => ['match' => 'all', 'rules' => []]]);

        self::assertStringStartsWith('This rule has conditions that Mago cannot read', $result['error'] ?? '');
        self::assertSame([], $this->apiClient->callsOf(FakeInternalApiClient::PUT));
    }

    #[Test]
    public function aPercentageAboveOneHundredIsRefusedForAPercentRule(): void
    {
        $result = $this->update(['discount_amount' => 150]);

        self::assertSame('discount_amount must be between 0 and 100 for a percentage discount', $result['error'] ?? null);
        self::assertSame([], $this->apiClient->callsOf(FakeInternalApiClient::PUT));
    }

    #[Test]
    public function aCallWithoutChangesIsRefusedBeforeTheCard(): void
    {
        $refusal = $this->action()->findRefusal(['rule_id' => self::RULE_ID, 'is_active' => false]);

        self::assertStringStartsWith('update_rule needs at least one of', $refusal['error'] ?? '');
    }

    #[Test]
    public function fieldsSentAsNullAreLeftAsTheyAre(): void
    {
        $this->update(['discount_amount' => 15, 'from_date' => null, 'description' => null, 'discount_qty' => null]);

        $saved = $this->savedRule();
        self::assertSame('2026-01-01', $saved['from_date']);
        self::assertSame(0, $saved['discount_qty']);
        self::assertArrayNotHasKey('description', $saved);
    }

    #[Test]
    public function sharedParametersOfOtherActionsDoNotBlockAnUpdate(): void
    {
        $refusal = $this->action()->findRefusal([
            'rule_id' => self::RULE_ID,
            'name' => 'Renamed',
            'website_ids' => [],
            'coupon_code' => '',
            'discount_type' => 'percent',
        ]);

        self::assertNull($refusal);
    }

    #[Test]
    public function moreFreeThanBoughtIsRefusedOnABuyXGetYRule(): void
    {
        $this->apiClient->withResponse(FakeInternalApiClient::GET, 'salesRules/' . self::RULE_ID, [
            ...$this->openBoxRule(),
            'simple_action' => 'buy_x_get_y',
            'discount_step' => 2,
            'discount_amount' => 1,
        ]);

        $result = $this->update(['discount_amount' => 3]);

        self::assertStringStartsWith('buy_x_get_y cannot give more items free', $result['error'] ?? '');
        self::assertSame([], $this->apiClient->callsOf(FakeInternalApiClient::PUT));
    }

    #[Test]
    public function aBadDateIsRefusedBeforeTheCard(): void
    {
        $refusal = $this->action()->findRefusal(['rule_id' => self::RULE_ID, 'to_date' => 'next friday']);

        self::assertStringContainsString('YYYY-MM-DD', $refusal['error'] ?? '');
    }

    #[Test]
    public function theCardShowsTheConditionsBeforeAndAfter(): void
    {
        $cautions = $this->action()->getCautions([
            'rule_id' => self::RULE_ID,
            'discount_amount' => 15,
            'actions' => ['match' => 'all', 'rules' => [
                ['attribute' => 'category_ids', 'operator' => 'not_in', 'value' => [RuleTargetFixture::MEN_TEES]],
            ]],
        ], self::ADMIN_USER_ID);

        self::assertSame([
            'Changes cart price rule 12 "Open box deals":',
            'Discount amount: "10" -> "15".',
            'Actions now: Discounts only items where category is Default Category > Men > Tees (16).',
            'Actions after: Discounts only items where category is not Default Category > Men > Tees (16).',
            'Check the category: 2 categories are named "Tees". This rule uses Default Category > Men > Tees (16), '
                . 'not Default Category > Women > Tees (25).',
        ], $cautions);
    }

    #[Test]
    public function aRuleWithOddDiscountSettingsFromTheAdminCanStillBeRenamed(): void
    {
        $this->apiClient->withResponse(FakeInternalApiClient::GET, 'salesRules/' . self::RULE_ID, [
            ...$this->openBoxRule(),
            'simple_action' => 'buy_x_get_y',
            'discount_step' => 0,
        ]);

        $result = $this->update(['name' => 'Renamed']);

        self::assertTrue($result['success'] ?? false);
        self::assertSame('Renamed', $this->savedRule()['name']);
    }

    private function action(): UpdateRuleAction
    {
        return RuleTargetFixture::updateAction($this->apiClient);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function update(array $params): array
    {
        return $this->action()->execute($params + ['rule_id' => self::RULE_ID], self::ADMIN_USER_ID);
    }

    /**
     * @return array<string, mixed>
     */
    private function savedRule(): array
    {
        return $this->apiClient->callsOf(FakeInternalApiClient::PUT)[0]['payload']['rule'];
    }

    /**
     * @return array<string, mixed>
     */
    private function openBoxRule(): array
    {
        $builder = new RuleConditionBuilder();

        return [
            'rule_id' => self::RULE_ID,
            'name' => 'Open box deals',
            'is_active' => true,
            'simple_action' => 'by_percent',
            'discount_amount' => 10,
            'discount_qty' => 0,
            'discount_step' => 0,
            'simple_free_shipping' => '0',
            'website_ids' => [1],
            'customer_group_ids' => [0, 1],
            'from_date' => '2026-01-01',
            'condition' => $builder->buildConditions(['match' => 'all', 'rules' => []]),
            'action_condition' => $builder->buildActions(['match' => 'all', 'rules' => [
                ['attribute' => 'category_ids', 'operator' => 'in', 'value' => [RuleTargetFixture::MEN_TEES]],
            ]]),
        ];
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Marketing\CouponManager;

use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\GetRuleAction;
use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleConditionBuilder;
use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleConditionDescriber;
use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleConditionReader;
use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleState;
use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleTargetCatalog;
use MagoAssistant\Mago\Service\Time\StoreTime;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeBackendUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeInternalApiClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #328: get_rule returns conditions and actions in the words update_rule takes, with a plain
 * summary, and a tree Mago cannot read comes back raw and flagged.
 */
final class GetRuleConditionsTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    private FakeInternalApiClient $apiClient;

    protected function setUp(): void
    {
        $this->apiClient = RuleTargetFixture::store();
    }

    #[Test]
    public function aReadableRuleComesBackInToolWordsWithASummary(): void
    {
        $conditions = ['match' => 'all', 'rules' => [['field' => 'subtotal', 'operator' => '>=', 'value' => 50.0]]];
        $this->givenRule(['condition' => (new RuleConditionBuilder())->buildConditions($conditions)]);

        $rule = $this->getRule();

        self::assertSame($conditions, $rule['conditions']);
        self::assertSame('Applies when the subtotal (base currency) is at least 50.', $rule['conditions_summary']);
        self::assertSame('Discounts every item in the cart.', $rule['actions_summary']);
        self::assertArrayNotHasKey('condition', $rule);
        self::assertSame(3, $rule['discount_qty']);
    }

    #[Test]
    public function anUnreadableTreeComesBackRawAndFlagged(): void
    {
        $raw = [
            'condition_type' => 'Magento\\SalesRule\\Model\\Rule\\Condition\\Combine',
            'aggregator_type' => 'all',
            'value' => '1',
            'conditions' => [['condition_type' => 'Vendor\\Module\\Condition', 'value' => '1']],
        ];
        $this->givenRule(['condition' => $raw]);

        $rule = $this->getRule();

        self::assertFalse($rule['conditions_readable']);
        self::assertStringContainsString('update_rule cannot change them', $rule['conditions_note']);
        self::assertSame($raw, $rule['condition']);
        self::assertArrayNotHasKey('conditions', $rule);
    }

    /**
     * @param array<string, mixed> $trees
     */
    private function givenRule(array $trees): void
    {
        $this->apiClient->withResponse(FakeInternalApiClient::GET, 'salesRules/9', [
            'rule_id' => 9,
            'name' => 'Spend 50',
            'is_active' => true,
            'coupon_type' => 'NO_COUPON',
            'simple_action' => 'by_percent',
            'discount_amount' => 5,
            'discount_qty' => 3,
            ...$trees,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function getRule(): array
    {
        $action = new GetRuleAction(
            $this->apiClient,
            new SecureAdminUrl(new FakeBackendUrl()),
            new RuleState($this->createStub(StoreTime::class)),
            new RuleConditionReader(),
            new RuleConditionDescriber(new RuleTargetCatalog($this->apiClient))
        );

        return $action->execute(['rule_id' => 9], self::ADMIN_USER_ID)['rule'];
    }
}

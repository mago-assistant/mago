<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Marketing\CouponManager;

use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\GetRuleAction;
use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\ListRulesAction;
use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleState;
use MagoAssistant\Mago\Service\Time\StoreTime;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeInternalApiClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #277: the rule payload has no coupon code, and is_active alone calls an expired rule active.
 */
class RuleLookupActionsTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    private const SUMMER10 = [
        'rule_id' => 5,
        'name' => 'Summer sale',
        'is_active' => true,
        'coupon_type' => 'SPECIFIC_COUPON',
        'use_auto_generation' => false,
        'from_date' => '2026-07-01',
        'to_date' => '2026-09-30',
        'simple_action' => 'by_percent',
        'discount_amount' => 10,
    ];

    private const NO_COUPON_RULE = [
        'rule_id' => 6,
        'name' => 'Free shipping over 100',
        'is_active' => true,
        'coupon_type' => 'NO_COUPON',
        'from_date' => null,
        'to_date' => null,
        'simple_action' => 'by_percent',
        'discount_amount' => 0,
    ];

    private FakeInternalApiClient $apiClient;

    protected function setUp(): void
    {
        $this->apiClient = new FakeInternalApiClient();
    }

    #[Test]
    public function getRuleReturnsThePrimaryCouponCodeAndTheExpiredState(): void
    {
        $this->apiClient
            ->withResponse(FakeInternalApiClient::GET, 'salesRules/5', self::SUMMER10)
            ->withResponse(FakeInternalApiClient::GET, 'coupons/search', [
                'items' => [['rule_id' => 5, 'code' => 'SUMMER10', 'is_primary' => true]],
                'total_count' => 1,
            ]);

        $rule = $this->getRule(5)['rule'];

        self::assertSame('SUMMER10', $rule['coupon_code']);
        self::assertSame(RuleState::SPECIFIC_COUPON, $rule['coupon_type']);
        self::assertTrue($rule['is_active']);
        self::assertSame(RuleState::EXPIRED, $rule['effective_status']);
        self::assertArrayNotHasKey('coupon_count', $rule);
        self::assertStringContainsString('rule_id', $this->searchOf('coupons/search'));
    }

    #[Test]
    public function getRuleReturnsACountAndASampleForGeneratedCoupons(): void
    {
        $this->apiClient
            ->withResponse(FakeInternalApiClient::GET, 'salesRules/8', ['coupon_type' => 'AUTO'] + self::SUMMER10)
            ->withResponse(FakeInternalApiClient::GET, 'coupons/search', [
                'items' => [['code' => 'AB12'], ['code' => 'CD34']],
                'total_count' => 250,
            ]);

        $rule = $this->getRule(8)['rule'];

        self::assertNull($rule['coupon_code']);
        self::assertSame(250, $rule['coupon_count']);
        self::assertSame(['AB12', 'CD34'], $rule['coupon_codes']);
    }

    #[Test]
    public function getRuleWithoutACouponDoesNotSearchCoupons(): void
    {
        $this->apiClient->withResponse(FakeInternalApiClient::GET, 'salesRules/6', self::NO_COUPON_RULE);

        $rule = $this->getRule(6)['rule'];

        self::assertNull($rule['coupon_code']);
        self::assertSame(RuleState::NO_COUPON, $rule['coupon_type']);
        self::assertSame(RuleState::ACTIVE, $rule['effective_status']);
        self::assertSame([], $this->callsTo('coupons/search'));
    }

    #[Test]
    public function aFailedCouponLookupIsReportedInsteadOfLookingLikeNoCoupon(): void
    {
        $this->apiClient
            ->withResponse(FakeInternalApiClient::GET, 'salesRules/5', self::SUMMER10)
            ->withResponse(FakeInternalApiClient::GET, 'coupons/search', ['error' => 'Access denied']);

        $rule = $this->getRule(5)['rule'];

        self::assertNull($rule['coupon_code']);
        self::assertSame('Access denied', $rule['coupon_lookup_error']);
    }

    #[Test]
    public function listRulesAddsCouponCodesAndStatesInOneCouponSearch(): void
    {
        $this->apiClient
            ->withResponse(FakeInternalApiClient::GET, 'salesRules/search', [
                'items' => [self::SUMMER10, self::NO_COUPON_RULE],
                'total_count' => 2,
            ])
            ->withResponse(FakeInternalApiClient::GET, 'coupons/search', [
                'items' => [['rule_id' => 5, 'code' => 'SUMMER10', 'is_primary' => true]],
            ]);

        $result = $this->listRules();
        $byId = array_column($result['rules'], null, 'rule_id');

        self::assertSame('SUMMER10', $byId[5]['coupon_code']);
        self::assertSame(RuleState::EXPIRED, $byId[5]['effective_status']);
        self::assertNull($byId[6]['coupon_code']);
        self::assertSame(RuleState::ACTIVE, $byId[6]['effective_status']);
        self::assertCount(1, $this->callsTo('coupons/search'));
        self::assertStringContainsString('"in"', $this->searchOf('coupons/search'));
        self::assertStringContainsString('is_primary', $this->searchOf('coupons/search'));
        self::assertArrayNotHasKey('coupon_lookup_error', $result);
    }

    #[Test]
    public function listRulesWithoutSpecificCouponRulesDoesNotSearchCoupons(): void
    {
        $this->apiClient->withResponse(FakeInternalApiClient::GET, 'salesRules/search', [
            'items' => [self::NO_COUPON_RULE],
            'total_count' => 1,
        ]);

        $this->listRules();

        self::assertSame([], $this->callsTo('coupons/search'));
    }

    #[Test]
    public function theNewFieldsAreClassified(): void
    {
        $get = $this->getRuleAction();
        $list = $this->listRulesAction();

        foreach (['effective_status', 'coupon_code', 'coupon_count', 'coupon_codes', 'coupon_lookup_error'] as $field) {
            self::assertArrayHasKey($field, $get->getFieldClassification(), 'get_rule ' . $field);
        }
        foreach (['effective_status', 'coupon_code', 'coupon_lookup_error'] as $field) {
            self::assertArrayHasKey($field, $list->getFieldClassification(), 'list_rules ' . $field);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getRule(int $ruleId): array
    {
        return $this->getRuleAction()->execute(['rule_id' => $ruleId], self::ADMIN_USER_ID);
    }

    /**
     * @return array<string, mixed>
     */
    private function listRules(): array
    {
        return $this->listRulesAction()->execute([], self::ADMIN_USER_ID);
    }

    private function getRuleAction(): GetRuleAction
    {
        return new GetRuleAction($this->apiClient, $this->secureAdminUrl(), $this->ruleState());
    }

    private function listRulesAction(): ListRulesAction
    {
        return new ListRulesAction($this->apiClient, $this->secureAdminUrl(), $this->ruleState());
    }

    private function secureAdminUrl(): SecureAdminUrl
    {
        $secureAdminUrl = $this->createStub(SecureAdminUrl::class);
        $secureAdminUrl->method('getUrl')->willReturn('https://example.test/admin/rule');

        return $secureAdminUrl;
    }

    private function ruleState(): RuleState
    {
        $storeTime = $this->createStub(StoreTime::class);
        $storeTime->method('now')->willReturn(
            new \DateTimeImmutable('2026-10-12 10:00:00', new \DateTimeZone('Europe/Amsterdam'))
        );

        return new RuleState($storeTime);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function callsTo(string $endpoint): array
    {
        return array_values(array_filter(
            $this->apiClient->callsOf(FakeInternalApiClient::GET),
            static fn (array $call): bool => $call['endpoint'] === $endpoint
        ));
    }

    private function searchOf(string $endpoint): string
    {
        return (string)json_encode($this->callsTo($endpoint)[0]['payload'] ?? []);
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Marketing\CouponManager;

use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleState;
use MagoAssistant\Mago\Service\Time\StoreTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RuleStateTest extends TestCase
{
    public static function ruleOnToday(): array
    {
        return [
            'switched off' => [['is_active' => false, 'to_date' => '2026-12-31'], RuleState::INACTIVE],
            'no dates' => [['is_active' => true], RuleState::ACTIVE],
            'within its dates' => [
                ['is_active' => true, 'from_date' => '2026-10-01', 'to_date' => '2026-10-31'],
                RuleState::ACTIVE,
            ],
            'ends today' => [['is_active' => true, 'to_date' => '2026-10-12'], RuleState::ACTIVE],
            'starts today' => [['is_active' => true, 'from_date' => '2026-10-12'], RuleState::ACTIVE],
            'ended yesterday' => [
                ['is_active' => true, 'from_date' => '2026-07-01', 'to_date' => '2026-10-11'],
                RuleState::EXPIRED,
            ],
            'starts tomorrow' => [['is_active' => true, 'from_date' => '2026-10-13'], RuleState::SCHEDULED],
            'switched off and expired' => [['is_active' => false, 'to_date' => '2026-09-30'], RuleState::INACTIVE],
            'unreadable date' => [['is_active' => true, 'to_date' => 'soon'], RuleState::ACTIVE],
        ];
    }

    /**
     * @param array<string, mixed> $rule
     */
    #[Test]
    #[DataProvider('ruleOnToday')]
    public function theStateFollowsTheDatesOnTheStoreClock(array $rule, string $expected): void
    {
        self::assertSame($expected, $this->ruleState('2026-10-12 00:30:00')->effectiveStatus($rule));
    }

    public static function couponTypes(): array
    {
        return [
            'REST string, no coupon' => [['coupon_type' => 'NO_COUPON'], RuleState::NO_COUPON],
            'REST string, specific' => [['coupon_type' => 'SPECIFIC_COUPON'], RuleState::SPECIFIC_COUPON],
            'REST string, auto' => [['coupon_type' => 'AUTO'], RuleState::AUTO_GENERATED],
            'specific with auto generation' => [
                ['coupon_type' => 'SPECIFIC_COUPON', 'use_auto_generation' => true],
                RuleState::AUTO_GENERATED,
            ],
            'raw table value' => [['coupon_type' => 2], RuleState::SPECIFIC_COUPON],
            'missing' => [[], RuleState::NO_COUPON],
            'unknown' => [['coupon_type' => 'SOMETHING_ELSE'], RuleState::UNKNOWN],
        ];
    }

    /**
     * @param array<string, mixed> $rule
     */
    #[Test]
    #[DataProvider('couponTypes')]
    public function theCouponTypeIsReadFromTheRestPayload(array $rule, string $expected): void
    {
        self::assertSame($expected, $this->ruleState('2026-10-12 12:00:00')->couponType($rule));
    }

    private function ruleState(string $now): RuleState
    {
        $storeTime = $this->createStub(StoreTime::class);
        $storeTime->method('now')->willReturn(
            new \DateTimeImmutable($now, new \DateTimeZone('Europe/Amsterdam'))
        );

        return new RuleState($storeTime);
    }
}

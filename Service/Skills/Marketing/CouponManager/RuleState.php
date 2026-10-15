<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CouponManager;

use MagoAssistant\Mago\Service\Time\StoreTime;

/**
 * What a cart price rule looks like to an administrator, rather than how the REST payload stores it.
 *
 * is_active is only the switch on the rule. A rule that is switched on but whose to_date has passed
 * no longer applies, and Magento compares those dates against the store's local date, so the state
 * the admin cares about is derived here on the store's clock (#277).
 */
class RuleState
{
    public const ACTIVE = 'active';
    public const SCHEDULED = 'scheduled';
    public const EXPIRED = 'expired';
    public const INACTIVE = 'inactive';

    public const NO_COUPON = 'no_coupon';
    public const SPECIFIC_COUPON = 'specific_coupon';
    public const AUTO_GENERATED = 'auto_generated';
    public const UNKNOWN = 'unknown';

    /**
     * The REST data model carries the coupon type as a string; the numeric keys are what the
     * salesrule table stores, accepted in case a payload carries the raw value.
     */
    private const COUPON_TYPES = [
        'NO_COUPON' => self::NO_COUPON,
        'SPECIFIC_COUPON' => self::SPECIFIC_COUPON,
        'AUTO' => self::AUTO_GENERATED,
        '1' => self::NO_COUPON,
        '2' => self::SPECIFIC_COUPON,
        '3' => self::AUTO_GENERATED,
    ];

    public function __construct(
        private readonly StoreTime $storeTime
    ) {
    }

    /**
     * @param array<string, mixed> $rule
     */
    public function couponType(array $rule): string
    {
        $raw = $rule['coupon_type'] ?? 'NO_COUPON';
        $type = self::COUPON_TYPES[strtoupper(is_scalar($raw) ? (string)$raw : '')] ?? self::UNKNOWN;

        // A specific-coupon rule with "Use Auto Generation" has generated codes and no single code.
        if ($type === self::SPECIFIC_COUPON && !empty($rule['use_auto_generation'])) {
            return self::AUTO_GENERATED;
        }

        return $type;
    }

    /**
     * @param array<string, mixed> $rule
     */
    public function effectiveStatus(array $rule): string
    {
        if (empty($rule['is_active'])) {
            return self::INACTIVE;
        }

        $today = $this->storeTime->now()->format('Y-m-d');
        $from = $this->date($rule['from_date'] ?? null);
        $to = $this->date($rule['to_date'] ?? null);

        if ($from !== '' && $from > $today) {
            return self::SCHEDULED;
        }
        if ($to !== '' && $to < $today) {
            return self::EXPIRED;
        }

        return self::ACTIVE;
    }

    private function date(mixed $value): string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1 ? substr($value, 0, 10) : '';
    }
}

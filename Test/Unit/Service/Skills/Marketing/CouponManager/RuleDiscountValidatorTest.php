<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Marketing\CouponManager;

use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleDiscountValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #328: create_rule and update_rule check discount settings in one place.
 */
final class RuleDiscountValidatorTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: mixed, 2: mixed, 3: mixed, 4: string}>
     */
    public static function refusedSettings(): array
    {
        return [
            'negative amount' => ['by_fixed', -1, 0, 0, 'discount_amount must be 0 or greater'],
            'amount that is not a number' => ['by_fixed', 'ten', 0, 0, 'discount_amount must be 0 or greater'],
            'percentage over 100' => ['by_percent', 101, 0, 0, 'between 0 and 100'],
            'fractional quantity' => ['by_percent', 10, 0, 1.5, 'discount_qty must be a whole number'],
            'negative step' => ['by_percent', 10, -2, 0, 'discount_step must be a whole number'],
            'buy x get y without a step' => ['buy_x_get_y', 1, 0, 0, 'buy_x_get_y needs discount_step'],
            'buy x get y with half an item free' => ['buy_x_get_y', 1.5, 2, 0, 'buy_x_get_y needs discount_step'],
            'more free than bought' => ['buy_x_get_y', 2, 1, 0, 'cannot give more items free'],
        ];
    }

    #[Test]
    #[DataProvider('refusedSettings')]
    public function settingsMagentoWouldMisapplyAreRefused(
        string $simpleAction,
        mixed $amount,
        mixed $step,
        mixed $quantity,
        string $expected
    ): void {
        $error = (new RuleDiscountValidator())->findError($simpleAction, $amount, $step, $quantity);

        self::assertStringContainsString($expected, (string)$error);
    }

    /**
     * @return array<string, array{0: string, 1: mixed, 2: mixed, 3: mixed}>
     */
    public static function acceptedSettings(): array
    {
        return [
            'one hundred percent' => ['by_percent', 100, 0, 0],
            'a large fixed amount' => ['by_fixed', 500, 0, 0],
            'buy 3 pay 2' => ['buy_x_get_y', 1, 2, 0],
            'buy 3 get the 4th free' => ['buy_x_get_y', 1, 3, 0],
            'as many free as bought' => ['buy_x_get_y', 2, 2, 4],
            'free shipping keeps no percentage bound' => ['free_shipping', 500, 0, 0],
        ];
    }

    #[Test]
    #[DataProvider('acceptedSettings')]
    public function settingsMagentoAppliesArePassed(string $simpleAction, mixed $amount, mixed $step, mixed $quantity): void
    {
        self::assertNull((new RuleDiscountValidator())->findError($simpleAction, $amount, $step, $quantity));
    }
}

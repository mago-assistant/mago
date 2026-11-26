<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CouponManager;

/**
 * The discount settings of a cart price rule, checked one way for create_rule and update_rule:
 * the amount, the maximum quantity, the step, and what buy X get Y needs to ever apply.
 */
class RuleDiscountValidator
{
    public const BY_PERCENT = 'by_percent';
    public const BUY_X_GET_Y = 'buy_x_get_y';

    private const MAX_PERCENTAGE = 100;

    /**
     * @param string $simpleAction The Magento simple_action the amount is spent as; any other value
     *                             (such as free shipping, whose amount is discarded) skips the
     *                             checks that belong to a percentage or to buy X get Y
     */
    public function findError(string $simpleAction, mixed $amount, mixed $step, mixed $quantity): ?string
    {
        $discountAmount = is_numeric($amount) ? (float)$amount : -1.0;

        return match (true) {
            // A negative amount raises the grand total instead of discounting it, whatever the type.
            $discountAmount < 0 => 'discount_amount must be 0 or greater',
            // Stricter than Magento's own form, which clamps a percentage to 100 only at apply time:
            // above 100 can only be a mistake in the call.
            $simpleAction === self::BY_PERCENT && $discountAmount > self::MAX_PERCENTAGE
                => 'discount_amount must be between 0 and 100 for a percentage discount',
            !$this->isCount($quantity) => 'discount_qty must be a whole number of 0 or more',
            !$this->isCount($step) => 'discount_step must be a whole number of 0 or more',
            $simpleAction === self::BUY_X_GET_Y => $this->findBuyXGetYError($discountAmount, (int)$step),
            default => null,
        };
    }

    /**
     * Magento's BuyXGetY gives nothing without a step, or when more items are free than bought.
     */
    private function findBuyXGetYError(float $itemsFree, int $itemsBought): ?string
    {
        return match (true) {
            $itemsBought < 1 || $itemsFree < 1 || floor($itemsFree) !== $itemsFree
                => 'buy_x_get_y needs discount_step (items bought) and discount_amount (items free) of 1 or more, '
                    . 'as whole numbers',
            $itemsFree > $itemsBought
                => 'buy_x_get_y cannot give more items free (discount_amount) than are bought (discount_step): '
                    . 'Magento never applies such a rule',
            default => null,
        };
    }

    private function isCount(mixed $value): bool
    {
        return is_numeric($value) && (float)$value >= 0 && floor((float)$value) === (float)$value;
    }
}

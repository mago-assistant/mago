<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing;

use MagoAssistant\Mago\Service\Skills\AbstractSkill;

class CouponManager extends AbstractSkill
{
    public function getName(): string
    {
        return 'coupon_manager';
    }

    protected function getBaseDescription(): string
    {
        return 'Manage cart price rules and coupon codes: list, create, update and deactivate discount rules, '
            . 'including conditions on the cart and which items get the discount.';
    }

    public function getMagentoAcl(array $input = []): string
    {
        return 'Magento_SalesRule::quote';
    }

    protected function getBaseInstructions(): string
    {
        return 'When creating rules, always confirm the discount type and amount with the user. '
            . 'Include admin_url links in results so the user can review rules in the admin panel. '
            . 'is_active is only the switch on a rule. Whether the rule applies today is effective_status: '
            . 'active, scheduled (starts on from_date), expired (ended on to_date) or inactive (switched off). '
            . 'Report effective_status as the rule\'s state, also in any status badge, and never call an '
            . 'expired or scheduled rule active. A rule\'s coupon code is coupon_code; when it is empty for a '
            . 'specific_coupon rule, say the code could not be read rather than that the rule has none. '
            . 'Conditions and actions take ids: resolve category, attribute and option names with '
            . 'find_rule_targets before proposing create_rule or update_rule, never from memory, and ask the '
            . 'user when the name they gave matches more than one. '
            . 'When get_rule returns conditions_readable or actions_readable false, say that part can only be '
            . 'changed in the admin, link admin_url, and do not offer to change it. '
            . 'Magento has no "cheapest item" discount: say so and offer buy_x_get_y instead. '
            . '"Buy 3, pay 2" is buy_x_get_y with discount_step 2 and discount_amount 1.';
    }
}

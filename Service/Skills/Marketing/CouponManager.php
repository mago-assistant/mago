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
        return 'Manage cart price rules and coupon codes: list, create, and deactivate discount rules.';
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
            . 'specific_coupon rule, say the code could not be read rather than that the rule has none.';
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CouponManager;

use MagoAssistant\Mago\Api\InternalApiClientInterface;
use MagoAssistant\Mago\Api\Skill\CautionedActionInterface;
use MagoAssistant\Mago\Api\Skill\ValidatingActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Skills\Marketing\CouponManager\RuleConditionVocabulary as Vocabulary;
use MagoAssistant\Mago\Service\Skills\Marketing\DiscountRuleCautions;
use MagoAssistant\Mago\Service\Skills\Marketing\RuleIds;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class CreateRuleAction implements CautionedActionInterface, ValidatingActionInterface
{
    private const COUPON_TYPE_MANUAL = 0;
    private const TYPE_PERCENT = 'percent';
    private const TYPE_FIXED = 'fixed';
    private const TYPE_FREE_SHIPPING = 'free_shipping';
    private const TYPE_BUY_X_GET_Y = 'buy_x_get_y';
    private const SIMPLE_ACTIONS = [
        self::TYPE_PERCENT => RuleDiscountValidator::BY_PERCENT,
        self::TYPE_FIXED => 'by_fixed',
        self::TYPE_FREE_SHIPPING => RuleDiscountValidator::BY_PERCENT,
        self::TYPE_BUY_X_GET_Y => RuleDiscountValidator::BUY_X_GET_Y,
    ];
    private const FREE_SHIPPING_FOR_MATCHING_ITEMS = '1';

    public function __construct(
        private readonly InternalApiClientInterface $apiClient,
        private readonly SecureAdminUrl $secureAdminUrl,
        private readonly DiscountRuleCautions $cautions,
        private readonly RuleConditionValidator $conditionValidator,
        private readonly RuleConditionBuilder $conditionBuilder,
        private readonly RuleConditionDescriber $conditionDescriber,
        private readonly RuleDiscountValidator $discountValidator
    ) {
    }

    public function getName(): string
    {
        return 'create_rule';
    }

    public function getDescription(): string
    {
        return 'Create a new cart price rule with optional coupon code, conditions on the cart and actions '
            . 'limiting which items get the discount';
    }

    public function getParameterSchema(): array
    {
        return [
            'name' => [
                'type' => 'string',
                'description' => 'Name for the cart price rule (required for create_rule)',
            ],
            'description' => [
                'type' => 'string',
                'description' => 'Internal description of the rule, shown only in the admin (optional)',
            ],
            'discount_type' => [
                'type' => 'string',
                'description' => 'Discount type: "percent", "fixed" (amount off each item), "free_shipping", or '
                    . '"buy_x_get_y" (discount_step items bought earn discount_amount items free). There is no '
                    . '"cheapest item" discount in Magento.',
            ],
            'discount_amount' => [
                'type' => 'number',
                'description' => 'Discount amount (e.g. 20 for 20% or 20 for $20 off), 0 or greater. '
                    . 'For "percent" this is a percentage and must be at most 100. For "buy_x_get_y" this is Y, '
                    . 'the number of items given free.',
            ],
            'discount_qty' => [
                'type' => 'integer',
                'description' => 'Maximum number of items the discount applies to per cart (0 or omitted: no limit)',
            ],
            'discount_step' => [
                'type' => 'integer',
                'description' => 'For "buy_x_get_y": X, the items bought per Y free ("buy 3, pay 2" is step 2, '
                    . 'amount 1). For "percent" and "fixed": the discount applies per this many items (0: every item).',
            ],
            'conditions' => Vocabulary::conditionsSchema(),
            'actions' => Vocabulary::actionsSchema(),
            'coupon_code' => [
                'type' => 'string',
                'description' => 'Specific coupon code (optional, omit for auto-apply rule)',
            ],
            'from_date' => [
                'type' => 'string',
                'description' => 'Start date in YYYY-MM-DD format (optional)',
            ],
            'to_date' => [
                'type' => 'string',
                'description' => 'End date in YYYY-MM-DD format (optional)',
            ],
            'website_ids' => [
                'type' => 'array',
                'description' => 'Website IDs to apply rule to (default: all websites)',
            ],
            'customer_group_ids' => [
                'type' => 'array',
                'description' => 'Customer group IDs (default: all groups)',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return null;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function getFieldClassification(): array
    {
        return [
            'admin_url' => [PiiClass::TOKENISE, 'url'],
            'success' => [PiiClass::PUBLIC],
            'rule_id' => [PiiClass::PUBLIC],
            'name' => [PiiClass::PUBLIC],
            'coupon_code' => [PiiClass::PUBLIC],
            'discount_type' => [PiiClass::PUBLIC],
            'discount_amount' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return 'Always confirm the discount type, amount, and coupon code with the user before creating a rule. '
            . 'Valid discount types are "percent" (percentage off), "fixed" (fixed amount off per item), '
            . '"free_shipping" (free shipping) and "buy_x_get_y". '
            . 'If no website_ids or customer_group_ids are provided, the rule applies to all websites and customer groups. '
            . 'Conditions and actions take ids, never names: look categories, promo attributes and their options up '
            . 'with find_rule_targets first, and when a name matches more than one, ask the user which one they mean.';
    }

    /**
     * A new cart rule changes what every matching order pays, and by default it reaches every cart
     * on every website, guests included, at once and without a coupon: always worth a second look.
     */
    public function getCautions(array $params, int $adminUserId): array
    {
        $conditions = $this->tree($params, 'conditions');
        $actions = $this->tree($params, 'actions');

        return [
            $this->describeDiscount($params, $conditions),
            ...($conditions === null ? [] : [$this->conditionDescriber->describeConditions($conditions, $adminUserId)]),
            ...($actions === null ? [] : [$this->conditionDescriber->describeActions($actions, $adminUserId)]),
            ...$this->conditionDescriber->describeSharedCategoryNames($conditions, $actions, $adminUserId),
            ...$this->describeQuantities($params),
            ...$this->cautions->describeScope(
                $this->chosenWebsiteIds($params),
                $this->chosenCustomerGroupIds($params),
                trim((string)($params['from_date'] ?? '')),
                trim((string)($params['to_date'] ?? '')),
                $adminUserId
            ),
        ];
    }

    public function findRefusal(array $params): ?array
    {
        $error = $this->findInputError($params);

        return $error === null ? null : ['error' => $error];
    }

    public function execute(array $params, int $adminUserId): array
    {
        $refusal = $this->findRefusal($params);
        if ($refusal !== null) {
            return $refusal;
        }

        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $conditions = $this->tree($params, 'conditions');
        $actions = $this->tree($params, 'actions');
        $targetError = $this->conditionValidator->findTargetError($conditions, $actions, $adminUserId);
        if ($targetError !== null) {
            return ['error' => $targetError];
        }

        $name = (string)$params['name'];
        $discountType = $this->discountType($params);
        $discountAmount = (float)($params['discount_amount'] ?? 0);

        // Determine coupon type and code
        $couponCode = $this->couponCode($params);
        $couponType = $couponCode !== '' ? 2 : 1; // 2 = specific coupon, 1 = no coupon

        // Read the way getCautions() reads them, so the rule saved is the rule the card described;
        // none given means every website (the admin website left out) and every customer group
        $websiteIds = $this->chosenWebsiteIds($params) ?? $this->getAllWebsiteIds($adminUserId);
        $customerGroupIds = $this->chosenCustomerGroupIds($params) ?? $this->getAllCustomerGroupIds($adminUserId);

        $ruleData = [
            'rule' => [
                'name' => $name,
                'description' => $params['description'] ?? '',
                'is_active' => true,
                'simple_action' => self::SIMPLE_ACTIONS[$discountType],
                'discount_amount' => $discountAmount,
                'discount_qty' => (int)($params['discount_qty'] ?? 0),
                'discount_step' => (int)($params['discount_step'] ?? 0),
                'apply_to_shipping' => false,
                'coupon_type' => $couponType,
                'use_auto_generation' => false,
                'website_ids' => $websiteIds,
                'customer_group_ids' => $customerGroupIds,
                'stop_rules_processing' => false,
                'sort_order' => 0,
                ...($conditions === null ? [] : ['condition' => $this->conditionBuilder->buildConditions($conditions)]),
                ...($actions === null ? [] : ['action_condition' => $this->conditionBuilder->buildActions($actions)]),
            ],
        ];

        if ($discountType === self::TYPE_FREE_SHIPPING) {
            $ruleData['rule']['simple_free_shipping'] = self::FREE_SHIPPING_FOR_MATCHING_ITEMS;
            $ruleData['rule']['discount_amount'] = 0;
        }

        if (!empty($params['from_date'])) {
            $ruleData['rule']['from_date'] = $params['from_date'];
        }
        if (!empty($params['to_date'])) {
            $ruleData['rule']['to_date'] = $params['to_date'];
        }

        $result = $this->apiClient->post('salesRules', $ruleData, $adminUserId);

        if (isset($result['error'])) {
            return ['error' => 'Failed to create rule: ' . $result['error']];
        }

        $ruleId = (int)($result['rule_id'] ?? 0);

        $couponError = $couponCode !== '' ? $this->addCoupon($ruleId, $couponCode, $adminUserId) : null;
        if ($couponError !== null) {
            return ['error' => $couponError];
        }

        return [
            'success' => true,
            'rule_id' => $ruleId,
            'name' => $result['name'] ?? $name,
            'coupon_code' => $couponCode !== '' ? $couponCode : null,
            'discount_type' => $discountType,
            'discount_amount' => $discountAmount,
            'admin_url' => $this->secureAdminUrl->getUrl(
                'sales_rule/promo_quote/edit',
                ['id' => $ruleId]
            ),
        ];
    }

    /**
     * Everything that can be checked without the store: refused before the caution card, and again
     * on execute for a confirm that skips it.
     *
     * @param array<string, mixed> $params
     */
    private function findInputError(array $params): ?string
    {
        if (trim((string)($params['name'] ?? '')) === '') {
            return 'name parameter is required for create_rule';
        }

        return $this->findDiscountError($params)
            ?? (isset($params['conditions']) ? $this->conditionValidator->findConditionsError($params['conditions']) : null)
            ?? (isset($params['actions']) ? $this->conditionValidator->findActionsError($params['actions']) : null);
    }

    /**
     * Free shipping is saved as by_percent with the amount set to 0, so it is checked as itself:
     * only a negative amount is refused, never a percentage over 100.
     *
     * @param array<string, mixed> $params
     */
    private function findDiscountError(array $params): ?string
    {
        $discountType = $this->discountType($params);

        return $this->discountValidator->findError(
            $discountType === self::TYPE_FREE_SHIPPING ? self::TYPE_FREE_SHIPPING : self::SIMPLE_ACTIONS[$discountType],
            (float)($params['discount_amount'] ?? 0),
            $params['discount_step'] ?? 0,
            $params['discount_qty'] ?? 0
        );
    }

    /**
     * Unknown types fall back to a percentage, as they always have.
     *
     * @param array<string, mixed> $params
     */
    private function discountType(array $params): string
    {
        $type = (string)($params['discount_type'] ?? self::TYPE_PERCENT);

        return isset(self::SIMPLE_ACTIONS[$type]) ? $type : self::TYPE_PERCENT;
    }

    /**
     * The tree as it goes into the rule, or null when the call leaves it out
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function tree(array $params, string $key): ?array
    {
        return isset($params[$key]) ? Vocabulary::treeFromInput($params[$key]) : null;
    }

    /**
     * The REST rule has no coupon code field: a specific coupon is its own record, saved once the
     * rule exists. A rule that cannot get its code is deleted again, or it would sit there active,
     * expecting a coupon nobody can enter.
     *
     * @return string|null the error, or null once the coupon is saved
     */
    private function addCoupon(int $ruleId, string $couponCode, int $adminUserId): ?string
    {
        $result = $this->apiClient->post('coupons', [
            'coupon' => [
                'rule_id' => $ruleId,
                'code' => $couponCode,
                'is_primary' => true,
                'type' => self::COUPON_TYPE_MANUAL,
            ],
        ], $adminUserId);
        if (!isset($result['error'])) {
            return null;
        }

        $this->apiClient->delete('salesRules/' . $ruleId, $adminUserId);

        return 'Failed to create coupon ' . $couponCode . ', so the rule was not kept: ' . $result['error'];
    }

    /**
     * "Every cart" only when nothing narrows it down; with conditions, the next line says which.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed>|null $conditions
     */
    private function describeDiscount(array $params, ?array $conditions): string
    {
        $amount = $this->cautions->formatAmount((float)($params['discount_amount'] ?? 0));
        $step = (string)(int)($params['discount_step'] ?? 0);
        $couponCode = $this->couponCode($params);
        $discount = match ($this->discountType($params)) {
            self::TYPE_FIXED => 'Takes ' . $amount . ' off each item in',
            self::TYPE_FREE_SHIPPING => 'Gives free shipping on',
            self::TYPE_BUY_X_GET_Y => 'Gives ' . $amount . ' item(s) free for every ' . $step
                . ' bought (buy ' . $step . ', get ' . $amount . ' free) in',
            default => 'Takes ' . $amount . '% off',
        };
        $isConditional = (array)($conditions['rules'] ?? []) !== [];

        return match (true) {
            $couponCode !== '' && $isConditional => $discount . ' carts that meet the conditions and use coupon '
                . $couponCode . '.',
            $couponCode !== '' => $discount . ' carts that use coupon ' . $couponCode . '.',
            $isConditional => $discount . ' carts that meet the conditions, automatically and without a coupon.',
            default => $discount . ' every cart, automatically and without a coupon.',
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return string[]
     */
    private function describeQuantities(array $params): array
    {
        $maximum = (int)($params['discount_qty'] ?? 0);
        $step = (int)($params['discount_step'] ?? 0);

        return [
            ...($maximum > 0 ? ['Discounts at most ' . $maximum . ' item(s) per cart.'] : []),
            ...($step > 0 && $this->discountType($params) !== self::TYPE_BUY_X_GET_Y
                ? ['Discounts items only in whole steps of ' . $step . '.']
                : []),
        ];
    }

    /**
     * Empty when the rule applies without a coupon. "0" is a coupon code like any other, not "none".
     *
     * @param array<string, mixed> $params
     */
    private function couponCode(array $params): string
    {
        return trim((string)($params['coupon_code'] ?? ''));
    }

    /**
     * @param array<string, mixed> $params
     * @return int[]|null null for every website
     */
    private function chosenWebsiteIds(array $params): ?array
    {
        return RuleIds::fromInput($params['website_ids'] ?? null) ?: null;
    }

    /**
     * @param array<string, mixed> $params
     * @return int[]|null null for every customer group
     */
    private function chosenCustomerGroupIds(array $params): ?array
    {
        return RuleIds::fromInput($params['customer_group_ids'] ?? null) ?: null;
    }

    private function getAllWebsiteIds(int $adminUserId): array
    {
        $result = $this->apiClient->get('store/websites', [], $adminUserId);
        if (isset($result['error'])) {
            return [1];
        }

        $ids = [];
        foreach ($result as $website) {
            $websiteId = (int)($website['id'] ?? 0);
            if ($websiteId > 0) {
                $ids[] = $websiteId;
            }
        }

        return $ids ?: [1];
    }

    private function getAllCustomerGroupIds(int $adminUserId): array
    {
        $searchParams = $this->apiClient->buildSearchCriteria([], 100);
        $result = $this->apiClient->get('customerGroups/search', $searchParams, $adminUserId);

        if (isset($result['error'])) {
            return [0, 1, 2, 3];
        }

        $ids = [];
        foreach ($result['items'] ?? [] as $group) {
            $ids[] = (int)($group['id'] ?? 0);
        }

        return $ids ?: [0, 1, 2, 3];
    }
}

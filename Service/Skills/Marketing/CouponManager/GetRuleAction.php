<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CouponManager;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Api\InternalApiClientInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class GetRuleAction implements ActionInterface
{
    private const SAMPLE_SIZE = 5;

    public function __construct(
        private readonly InternalApiClientInterface $apiClient,
        private readonly SecureAdminUrl $secureAdminUrl,
        private readonly RuleState $ruleState,
        private readonly RuleConditionReader $conditionReader,
        private readonly RuleConditionDescriber $conditionDescriber
    ) {
    }

    public function getName(): string
    {
        return 'get_rule';
    }

    public function getDescription(): string
    {
        return 'Get details of a specific cart price rule, including its coupon code(s) and whether it '
            . 'applies today';
    }

    public function getParameterSchema(): array
    {
        return [
            'rule_id' => [
                'type' => 'integer',
                'description' => 'The cart price rule ID',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return null;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function getFieldClassification(): array
    {
        // condition_type through value cover the nested condition/action_condition objects the
        // REST rule payload carries; rule conditions are shop data.
        return [
            'admin_url' => [PiiClass::TOKENISE, 'url'],
            'rule_id' => [PiiClass::PUBLIC],
            'name' => [PiiClass::PUBLIC],
            'description' => [PiiClass::PUBLIC],
            'is_active' => [PiiClass::PUBLIC],
            'effective_status' => [PiiClass::PUBLIC],
            'discount_amount' => [PiiClass::PUBLIC],
            'discount_type' => [PiiClass::PUBLIC],
            'simple_action' => [PiiClass::PUBLIC],
            'coupon_type' => [PiiClass::PUBLIC],
            'coupon_code' => [PiiClass::PUBLIC],
            'coupon_count' => [PiiClass::PUBLIC],
            'coupon_codes' => [PiiClass::PUBLIC],
            'coupon_lookup_error' => [PiiClass::PUBLIC],
            'uses_per_coupon' => [PiiClass::PUBLIC],
            'uses_per_customer' => [PiiClass::PUBLIC],
            'times_used' => [PiiClass::PUBLIC],
            'from_date' => [PiiClass::PUBLIC],
            'to_date' => [PiiClass::PUBLIC],
            'sort_order' => [PiiClass::PUBLIC],
            'stop_rules_processing' => [PiiClass::PUBLIC],
            'website_ids' => [PiiClass::PUBLIC],
            'customer_group_ids' => [PiiClass::PUBLIC],
            'simple_free_shipping' => [PiiClass::PUBLIC],
            'condition' => [PiiClass::PUBLIC],
            'action_condition' => [PiiClass::PUBLIC],
            'condition_type' => [PiiClass::PUBLIC],
            'aggregator_type' => [PiiClass::PUBLIC],
            'operator' => [PiiClass::PUBLIC],
            'attribute_name' => [PiiClass::PUBLIC],
            'value' => [PiiClass::PUBLIC],
            'discount_qty' => [PiiClass::PUBLIC],
            'discount_step' => [PiiClass::PUBLIC],
            'conditions' => [PiiClass::PUBLIC],
            'actions' => [PiiClass::PUBLIC],
            'conditions_summary' => [PiiClass::PUBLIC],
            'actions_summary' => [PiiClass::PUBLIC],
            'conditions_readable' => [PiiClass::PUBLIC],
            'actions_readable' => [PiiClass::PUBLIC],
            'conditions_note' => [PiiClass::PUBLIC],
            'actions_note' => [PiiClass::PUBLIC],
            'match' => [PiiClass::PUBLIC],
            'rules' => [PiiClass::PUBLIC],
            'field' => [PiiClass::PUBLIC],
            'presence' => [PiiClass::PUBLIC],
            'group' => [PiiClass::PUBLIC],
            'is' => [PiiClass::PUBLIC],
            'attribute' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return '';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function execute(array $params, int $adminUserId): array
    {
        $ruleId = (int)($params['rule_id'] ?? 0);
        if (!$ruleId) {
            return ['error' => 'rule_id parameter is required for get_rule'];
        }

        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $result = $this->apiClient->get('salesRules/' . $ruleId, [], $adminUserId);

        if (isset($result['error'])) {
            return $result;
        }

        $discountTypeMap = [
            'by_percent' => 'percent',
            'by_fixed' => 'fixed',
            'cart_fixed' => 'fixed_cart',
        ];

        $simpleAction = $result['simple_action'] ?? '';
        $couponType = $this->ruleState->couponType($result);

        return [
            'rule' => [
                'rule_id' => (int)($result['rule_id'] ?? 0),
                'name' => $result['name'] ?? '',
                'description' => $result['description'] ?? '',
                'is_active' => (bool)($result['is_active'] ?? false),
                'effective_status' => $this->ruleState->effectiveStatus($result),
                'discount_amount' => round((float)($result['discount_amount'] ?? 0), 2),
                'discount_type' => $discountTypeMap[$simpleAction] ?? $simpleAction,
                'simple_action' => $simpleAction,
                'coupon_type' => $couponType,
                ...$this->coupons($ruleId, $couponType, $adminUserId),
                'uses_per_coupon' => (int)($result['uses_per_coupon'] ?? 0),
                'uses_per_customer' => (int)($result['uses_per_customer'] ?? 0),
                'times_used' => (int)($result['times_used'] ?? 0),
                'from_date' => $result['from_date'] ?? null,
                'to_date' => $result['to_date'] ?? null,
                'sort_order' => (int)($result['sort_order'] ?? 0),
                'stop_rules_processing' => (bool)($result['stop_rules_processing'] ?? false),
                'website_ids' => $result['website_ids'] ?? [],
                'customer_group_ids' => $result['customer_group_ids'] ?? [],
                'simple_free_shipping' => (bool)($result['simple_free_shipping'] ?? false),
                'discount_qty' => (int)($result['discount_qty'] ?? 0),
                'discount_step' => (int)($result['discount_step'] ?? 0),
                ...$this->tree(
                    'conditions',
                    'condition',
                    $result,
                    $this->conditionReader->readConditions($result['condition'] ?? null),
                    $this->conditionDescriber->describeConditions(...),
                    $adminUserId
                ),
                ...$this->tree(
                    'actions',
                    'action_condition',
                    $result,
                    $this->conditionReader->readActions($result['action_condition'] ?? null),
                    $this->conditionDescriber->describeActions(...),
                    $adminUserId
                ),
                'admin_url' => $this->secureAdminUrl->getUrl(
                    'sales_rule/promo_quote/edit',
                    ['id' => (int)($result['rule_id'] ?? 0)]
                ),
            ],
        ];
    }

    /**
     * A tree Mago can read comes back in the words update_rule takes, with a plain summary. One it
     * cannot read comes back raw, flagged, so the model says it has to be changed in the admin.
     *
     * @param array<string, mixed> $rule
     * @param array<string, mixed>|null $readable
     * @param callable(array<string, mixed>, int): string $describe
     * @return array<string, mixed>
     */
    private function tree(
        string $key,
        string $restKey,
        array $rule,
        ?array $readable,
        callable $describe,
        int $adminUserId
    ): array {
        return $readable === null
            ? [
                $key . '_readable' => false,
                $key . '_note' => 'These ' . $key . ' hold parts Mago cannot read, so update_rule cannot change '
                    . 'them. They can only be changed in the admin (admin_url).',
                $restKey => $rule[$restKey] ?? null,
            ]
            : [$key => $readable, $key . '_summary' => $describe($readable, $adminUserId)];
    }

    /**
     * The rule payload has no coupon code: codes live in salesrule_coupon and are read through
     * coupons/search. A specific-coupon rule has one primary code; a generated one has many, so the
     * count and a few of them are returned instead.
     *
     * @return array<string, mixed>
     */
    private function coupons(int $ruleId, string $couponType, int $adminUserId): array
    {
        if ($couponType === RuleState::NO_COUPON) {
            return ['coupon_code' => null];
        }

        $result = $this->apiClient->get('coupons/search', $this->apiClient->buildSearchCriteria(
            [['field' => 'rule_id', 'value' => (string)$ruleId, 'condition_type' => 'eq']],
            self::SAMPLE_SIZE,
            1,
            [['field' => 'is_primary', 'direction' => 'DESC']]
        ), $adminUserId);

        if (isset($result['error'])) {
            return ['coupon_code' => null, 'coupon_lookup_error' => (string)$result['error']];
        }

        $primary = null;
        $codes = [];
        foreach ($result['items'] ?? [] as $coupon) {
            $code = (string)($coupon['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $codes[] = $code;
            if ($primary === null && !empty($coupon['is_primary'])) {
                $primary = $code;
            }
        }

        if ($couponType === RuleState::SPECIFIC_COUPON) {
            return ['coupon_code' => $primary ?? ($codes[0] ?? null)];
        }

        return [
            'coupon_code' => $primary,
            'coupon_count' => (int)($result['total_count'] ?? count($codes)),
            'coupon_codes' => $codes,
        ];
    }
}

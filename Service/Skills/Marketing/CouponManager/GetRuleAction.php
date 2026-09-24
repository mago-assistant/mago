<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CouponManager;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Api\InternalApiClient;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class GetRuleAction implements ActionInterface
{
    public function __construct(
        private readonly InternalApiClient $apiClient,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'get_rule';
    }

    public function getDescription(): string
    {
        return 'Get details of a specific cart price rule';
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
            'discount_amount' => [PiiClass::PUBLIC],
            'discount_type' => [PiiClass::PUBLIC],
            'simple_action' => [PiiClass::PUBLIC],
            'coupon_type' => [PiiClass::PUBLIC],
            'coupon_code' => [PiiClass::PUBLIC],
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
        ];
    }

    public function getInstructions(): string
    {
        return '';
    }

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

        $couponTypeMap = [
            1 => 'no_coupon',
            2 => 'specific_coupon',
            3 => 'auto_generated',
        ];

        $simpleAction = $result['simple_action'] ?? '';
        $couponType = (int)($result['coupon_type'] ?? 1);

        return [
            'rule' => [
                'rule_id' => (int)($result['rule_id'] ?? 0),
                'name' => $result['name'] ?? '',
                'description' => $result['description'] ?? '',
                'is_active' => (bool)($result['is_active'] ?? false),
                'discount_amount' => round((float)($result['discount_amount'] ?? 0), 2),
                'discount_type' => $discountTypeMap[$simpleAction] ?? $simpleAction,
                'simple_action' => $simpleAction,
                'coupon_type' => $couponTypeMap[$couponType] ?? 'unknown',
                'coupon_code' => $result['coupon_code'] ?? null,
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
                'condition' => $result['condition'] ?? null,
                'action_condition' => $result['action_condition'] ?? null,
                'admin_url' => $this->secureAdminUrl->getUrl(
                    'sales_rule/promo_quote/edit',
                    ['id' => (int)($result['rule_id'] ?? 0)]
                ),
            ],
        ];
    }
}

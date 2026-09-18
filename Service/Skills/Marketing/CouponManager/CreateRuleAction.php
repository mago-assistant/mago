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

class CreateRuleAction implements ActionInterface
{
    public function __construct(
        private readonly InternalApiClient $apiClient,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'create_rule';
    }

    public function getDescription(): string
    {
        return 'Create a new cart price rule with optional coupon code';
    }

    public function getParameterSchema(): array
    {
        return [
            'name' => [
                'type' => 'string',
                'description' => 'Name for the cart price rule (required)',
            ],
            'discount_type' => [
                'type' => 'string',
                'description' => 'Discount type: "percent", "fixed", or "free_shipping"',
            ],
            'discount_amount' => [
                'type' => 'number',
                'description' => 'Discount amount (e.g. 20 for 20% or 20 for $20 off)',
            ],
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
            . 'and "free_shipping" (free shipping). '
            . 'If no website_ids or customer_group_ids are provided, the rule applies to all websites and customer groups.';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $name = $params['name'] ?? '';
        if (empty($name)) {
            return ['error' => 'name parameter is required for create_rule'];
        }

        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $discountType = $params['discount_type'] ?? 'percent';
        $discountAmount = (float)($params['discount_amount'] ?? 0);

        // Map human-readable discount type to Magento simple_action
        $simpleAction = match ($discountType) {
            'percent' => 'by_percent',
            'fixed' => 'by_fixed',
            'free_shipping' => 'by_percent',
            default => 'by_percent',
        };

        // Determine coupon type and code
        $couponCode = trim((string)($params['coupon_code'] ?? ''));
        $couponType = $couponCode ? 2 : 1; // 2 = specific coupon, 1 = no coupon

        // Build website IDs - default to all (excluding admin website 0)
        $websiteIds = !empty($params['website_ids']) ? $params['website_ids'] : null;
        if (!$websiteIds) {
            $websiteIds = $this->getAllWebsiteIds($adminUserId);
        }
        $websiteIds = array_map('intval', array_values((array)$websiteIds));

        // Build customer group IDs - default to all
        $customerGroupIds = !empty($params['customer_group_ids']) ? $params['customer_group_ids'] : null;
        if (!$customerGroupIds) {
            $customerGroupIds = $this->getAllCustomerGroupIds($adminUserId);
        }
        $customerGroupIds = array_map('intval', array_values((array)$customerGroupIds));

        $ruleData = [
            'rule' => [
                'name' => $name,
                'description' => $params['description'] ?? '',
                'is_active' => true,
                'simple_action' => $simpleAction,
                'discount_amount' => $discountAmount,
                'discount_qty' => 0,
                'discount_step' => 0,
                'apply_to_shipping' => false,
                'coupon_type' => $couponType,
                'use_auto_generation' => false,
                'website_ids' => $websiteIds,
                'customer_group_ids' => $customerGroupIds,
                'stop_rules_processing' => false,
                'sort_order' => 0,
            ],
        ];

        if ($couponCode) {
            $ruleData['rule']['coupon_code'] = $couponCode;
        }

        if ($discountType === 'free_shipping') {
            $ruleData['rule']['simple_free_shipping'] = '1';
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

        return [
            'success' => true,
            'rule_id' => $ruleId,
            'name' => $result['name'] ?? $name,
            'coupon_code' => $result['coupon_code'] ?? $couponCode ?: null,
            'discount_type' => $discountType,
            'discount_amount' => $discountAmount,
            'admin_url' => $this->secureAdminUrl->getUrl(
                'sales_rule/promo_quote/edit',
                ['id' => $ruleId]
            ),
        ];
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

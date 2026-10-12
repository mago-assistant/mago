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

class ListRulesAction implements ActionInterface
{
    public function __construct(
        private readonly InternalApiClientInterface $apiClient,
        private readonly SecureAdminUrl $secureAdminUrl,
        private readonly RuleState $ruleState
    ) {
    }

    public function getName(): string
    {
        return 'list_rules';
    }

    public function getDescription(): string
    {
        return 'List cart price rules, optionally filtered by status, with each rule\'s coupon code and '
            . 'whether it applies today';
    }

    public function getParameterSchema(): array
    {
        return [
            'status' => [
                'type' => 'string',
                'description' => 'Filter on the rule\'s on/off switch: "active" or "inactive" (default: '
                    . '"active"). A switched-on rule can still be expired or scheduled: see effective_status',
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
        return [
            'admin_url' => [PiiClass::TOKENISE, 'url'],
            'total_count' => [PiiClass::PUBLIC],
            'rule_id' => [PiiClass::PUBLIC],
            'name' => [PiiClass::PUBLIC],
            'is_active' => [PiiClass::PUBLIC],
            'effective_status' => [PiiClass::PUBLIC],
            'discount_amount' => [PiiClass::PUBLIC],
            'discount_type' => [PiiClass::PUBLIC],
            'from_date' => [PiiClass::PUBLIC],
            'to_date' => [PiiClass::PUBLIC],
            'coupon_type' => [PiiClass::PUBLIC],
            'coupon_code' => [PiiClass::PUBLIC],
            'coupon_lookup_error' => [PiiClass::PUBLIC],
            'uses_per_coupon' => [PiiClass::PUBLIC],
            'times_used' => [PiiClass::PUBLIC],
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
        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $status = $params['status'] ?? 'active';
        $isActive = $status === 'active' ? '1' : '0';

        $searchParams = $this->apiClient->buildSearchCriteria(
            [['field' => 'is_active', 'value' => $isActive, 'condition_type' => 'eq']],
            100,
            1,
            [['field' => 'sort_order', 'direction' => 'ASC']]
        );

        $result = $this->apiClient->get('salesRules/search', $searchParams, $adminUserId);

        if (isset($result['error'])) {
            return $result;
        }

        $discountTypeMap = [
            'by_percent' => 'percent',
            'by_fixed' => 'fixed',
            'cart_fixed' => 'fixed_cart',
        ];

        $items = $result['items'] ?? [];
        $specificRuleIds = [];
        foreach ($items as $item) {
            $ruleId = (int)($item['rule_id'] ?? 0);
            if ($ruleId > 0 && $this->ruleState->couponType($item) === RuleState::SPECIFIC_COUPON) {
                $specificRuleIds[] = $ruleId;
            }
        }
        ['codes' => $codes, 'error' => $lookupError] = $this->primaryCodes($specificRuleIds, $adminUserId);

        $rules = [];
        foreach ($items as $item) {
            $ruleId = (int)($item['rule_id'] ?? 0);
            $simpleAction = $item['simple_action'] ?? '';
            $couponType = $this->ruleState->couponType($item);

            $rules[] = [
                'rule_id' => $ruleId,
                'name' => $item['name'] ?? '',
                'is_active' => (bool)($item['is_active'] ?? false),
                'effective_status' => $this->ruleState->effectiveStatus($item),
                'discount_amount' => round((float)($item['discount_amount'] ?? 0), 2),
                'discount_type' => $discountTypeMap[$simpleAction] ?? $simpleAction,
                'from_date' => $item['from_date'] ?? null,
                'to_date' => $item['to_date'] ?? null,
                'coupon_type' => $couponType,
                'coupon_code' => $codes[$ruleId] ?? null,
                'uses_per_coupon' => (int)($item['uses_per_coupon'] ?? 0),
                'times_used' => (int)($item['times_used'] ?? 0),
                'admin_url' => $this->secureAdminUrl->getUrl(
                    'sales_rule/promo_quote/edit',
                    ['id' => $ruleId]
                ),
            ];
        }

        $answer = [
            'total_count' => (int)($result['total_count'] ?? 0),
            'rules' => $rules,
        ];
        if ($lookupError !== null) {
            $answer['coupon_lookup_error'] = $lookupError;
        }

        return $answer;
    }

    /**
     * The primary coupon code of each specific-coupon rule, in one coupons/search call: the rule
     * payload itself carries no code.
     *
     * @param int[] $ruleIds
     * @return array{codes: array<int, string>, error: ?string}
     */
    private function primaryCodes(array $ruleIds, int $adminUserId): array
    {
        if ($ruleIds === []) {
            return ['codes' => [], 'error' => null];
        }

        $result = $this->apiClient->get('coupons/search', $this->apiClient->buildSearchCriteria(
            [
                ['field' => 'rule_id', 'value' => implode(',', $ruleIds), 'condition_type' => 'in'],
                ['field' => 'is_primary', 'value' => '1', 'condition_type' => 'eq'],
            ],
            count($ruleIds)
        ), $adminUserId);

        if (isset($result['error'])) {
            return ['codes' => [], 'error' => (string)$result['error']];
        }

        $codes = [];
        foreach ($result['items'] ?? [] as $coupon) {
            $ruleId = (int)($coupon['rule_id'] ?? 0);
            $code = (string)($coupon['code'] ?? '');
            if ($ruleId && $code !== '' && !isset($codes[$ruleId])) {
                $codes[$ruleId] = $code;
            }
        }

        return ['codes' => $codes, 'error' => null];
    }
}

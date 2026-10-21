<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CouponManager;

use MagoAssistant\Mago\Api\InternalApiClientInterface;
use MagoAssistant\Mago\Api\Skill\CautionedActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class CreateCouponAction implements CautionedActionInterface
{
    public function __construct(
        private readonly InternalApiClientInterface $apiClient,
        private readonly SecureAdminUrl $secureAdminUrl,
        private readonly RuleState $ruleState
    ) {
    }

    public function getName(): string
    {
        return 'create_coupon';
    }

    public function getDescription(): string
    {
        return 'Generate coupon code(s) for an existing cart price rule';
    }

    public function getParameterSchema(): array
    {
        return [
            'rule_id' => [
                'type' => 'integer',
                'description' => 'The cart price rule ID to generate coupons for',
            ],
            'quantity' => [
                'type' => 'integer',
                'description' => 'Number of coupon codes to generate (default: 1)',
            ],
            'length' => [
                'type' => 'integer',
                'description' => 'Length of generated coupon codes (default: 8)',
            ],
            'prefix' => [
                'type' => 'string',
                'description' => 'Prefix for generated coupon codes (optional)',
            ],
            'suffix' => [
                'type' => 'string',
                'description' => 'Suffix for generated coupon codes (optional)',
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
            'rule_name' => [PiiClass::PUBLIC],
            'codes' => [PiiClass::PUBLIC],
            'quantity_generated' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return 'The rule must have coupon_type "specific_coupon" or "auto_generated" for coupon generation to work. '
            . 'If the rule has coupon_type "no_coupon", inform the user that this rule does not support coupon codes.';
    }

    /**
     * Every generated code is a working discount for whoever holds it
     */
    public function getCautions(array $params, int $adminUserId): array
    {
        $ruleId = (int)($params['rule_id'] ?? 0);
        $rule = $this->apiClient->get('salesRules/' . $ruleId, [], $adminUserId);
        $quantity = $this->quantity($params);
        $ruleName = isset($rule['error']) || !isset($rule['name'])
            ? 'cart price rule ' . $ruleId
            : 'cart price rule "' . $rule['name'] . '" (' . $ruleId . ')';

        return [
            'Creates ' . $quantity . ' working coupon ' . ($quantity === 1 ? 'code' : 'codes') . ' for '
                . $ruleName . ', each giving its discount to whoever enters it.',
        ];
    }

    public function execute(array $params, int $adminUserId): array
    {
        $ruleId = (int)($params['rule_id'] ?? 0);
        if (!$ruleId) {
            return ['error' => 'rule_id parameter is required for create_coupon'];
        }

        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        // Verify rule exists and supports coupons
        $rule = $this->apiClient->get('salesRules/' . $ruleId, [], $adminUserId);
        if (isset($rule['error'])) {
            return $rule;
        }

        $couponType = $this->ruleState->couponType($rule);
        if ($couponType === RuleState::NO_COUPON) {
            return ['error' => 'Rule "' . ($rule['name'] ?? $ruleId) . '" does not use coupon codes (coupon_type is '
                . '"no_coupon"). Change the rule to use specific coupons first.'];
        }
        if ($couponType === RuleState::SPECIFIC_COUPON) {
            return ['error' => 'Rule "' . ($rule['name'] ?? $ruleId) . '" has one fixed coupon code. Codes can only '
                . 'be generated when "Use Auto Generation" is enabled on the rule.'];
        }

        $quantity = $this->quantity($params);
        $length = (int)($params['length'] ?? 8);

        if ($length < 4) {
            $length = 8;
        }

        $couponSpec = [
            'couponSpec' => [
                'rule_id' => $ruleId,
                'quantity' => $quantity,
                'length' => $length,
                'format' => 'alphanum',
            ],
        ];

        if (!empty($params['prefix'])) {
            $couponSpec['couponSpec']['prefix'] = $params['prefix'];
        }
        if (!empty($params['suffix'])) {
            $couponSpec['couponSpec']['suffix'] = $params['suffix'];
        }

        $result = $this->apiClient->post('coupons/generate', $couponSpec, $adminUserId);

        if (isset($result['error'])) {
            return ['error' => 'Failed to generate coupons: ' . $result['error']];
        }

        return [
            'success' => true,
            'rule_id' => $ruleId,
            'rule_name' => $rule['name'] ?? '',
            'codes' => $result,
            'quantity_generated' => count($result),
            'admin_url' => $this->secureAdminUrl->getUrl(
                'sales_rule/promo_quote/edit',
                ['id' => $ruleId]
            ),
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function quantity(array $params): int
    {
        return max(1, (int)($params['quantity'] ?? 1));
    }
}

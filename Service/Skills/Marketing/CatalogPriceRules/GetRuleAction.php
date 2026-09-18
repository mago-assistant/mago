<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules;

use Magento\CatalogRule\Api\CatalogRuleRepositoryInterface;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class GetRuleAction implements ActionInterface
{
    public function __construct(
        private readonly CatalogRuleRepositoryInterface $catalogRuleRepository,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'get_rule';
    }

    public function getDescription(): string
    {
        return 'Get details of a specific catalog price rule';
    }

    public function getParameterSchema(): array
    {
        return [
            'rule_id' => [
                'type' => 'integer',
                'description' => 'The catalog price rule ID',
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
        // type through aggregator cover the nested conditions tree from asArray(); rule
        // conditions are shop data.
        return [
            'rule_id' => [PiiClass::PUBLIC],
            'name' => [PiiClass::PUBLIC],
            'is_active' => [PiiClass::PUBLIC],
            'discount_amount' => [PiiClass::PUBLIC],
            'discount_type' => [PiiClass::PUBLIC],
            'from_date' => [PiiClass::PUBLIC],
            'to_date' => [PiiClass::PUBLIC],
            'sort_order' => [PiiClass::PUBLIC],
            'stop_further_rules' => [PiiClass::PUBLIC],
            'website_ids' => [PiiClass::PUBLIC],
            'customer_group_ids' => [PiiClass::PUBLIC],
            'type' => [PiiClass::PUBLIC],
            'attribute' => [PiiClass::PUBLIC],
            'operator' => [PiiClass::PUBLIC],
            'value' => [PiiClass::PUBLIC],
            'is_value_processed' => [PiiClass::PUBLIC],
            'attribute_scope' => [PiiClass::PUBLIC],
            'aggregator' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function execute(array $params, int $adminUserId): array
    {
        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $ruleId = (int)($params['rule_id'] ?? 0);
        if (!$ruleId) {
            return ['error' => 'rule_id parameter is required for get_rule'];
        }

        $discountTypeMap = [
            'by_percent' => 'percent',
            'by_fixed' => 'fixed',
            'to_percent' => 'to_percent',
            'to_fixed' => 'to_fixed',
        ];

        try {
            $rule = $this->catalogRuleRepository->get($ruleId);

            $simpleAction = $rule->getData('simple_action') ?? '';

            return [
                'rule' => [
                    'rule_id' => (int)$rule->getId(),
                    'name' => $rule->getData('name') ?? '',
                    'is_active' => (bool)$rule->getData('is_active'),
                    'discount_amount' => round((float)($rule->getData('discount_amount') ?? 0), 2),
                    'discount_type' => $discountTypeMap[$simpleAction] ?? $simpleAction,
                    'from_date' => $rule->getData('from_date') ?: null,
                    'to_date' => $rule->getData('to_date') ?: null,
                    'sort_order' => (int)($rule->getData('sort_order') ?? 0),
                    'stop_further_rules' => (bool)$rule->getData('stop_rules_processing'),
                    'website_ids' => $rule->getWebsiteIds(),
                    'customer_group_ids' => $rule->getCustomerGroupIds(),
                    'conditions' => $rule->getConditions()->asArray(),
                    'admin_url' => $this->secureAdminUrl->getUrl(
                        'catalog_rule/promo_catalog/edit',
                        ['id' => (int)$rule->getId()]
                    ),
                ],
            ];
        } catch (\Exception $e) {
            return ['error' => 'Failed to get catalog price rule: ' . $e->getMessage()];
        }
    }
}

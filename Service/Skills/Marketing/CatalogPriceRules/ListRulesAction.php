<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules;

use Magento\CatalogRule\Model\ResourceModel\Rule\CollectionFactory;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class ListRulesAction implements ActionInterface
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'list_rules';
    }

    public function getDescription(): string
    {
        return 'List catalog price rules, optionally filtered by status';
    }

    public function getParameterSchema(): array
    {
        return [
            'status' => [
                'type' => 'string',
                'description' => 'Filter by status: "active" or "inactive" (default: "active")',
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
            'discount_amount' => [PiiClass::PUBLIC],
            'discount_type' => [PiiClass::PUBLIC],
            'from_date' => [PiiClass::PUBLIC],
            'to_date' => [PiiClass::PUBLIC],
            'sort_order' => [PiiClass::PUBLIC],
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

        $status = $params['status'] ?? 'active';
        $isActive = $status === 'active' ? 1 : 0;

        $discountTypeMap = [
            'by_percent' => 'percent',
            'by_fixed' => 'fixed',
            'to_percent' => 'to_percent',
            'to_fixed' => 'to_fixed',
        ];

        try {
            $collection = $this->collectionFactory->create();
            $collection->addFieldToFilter('is_active', (string)$isActive);
            $collection->setOrder('sort_order', 'ASC');

            $rules = [];
            foreach ($collection as $rule) {
                $ruleId = (int)$rule->getId();
                $simpleAction = $rule->getData('simple_action') ?? '';

                $rules[] = [
                    'rule_id' => $ruleId,
                    'name' => $rule->getData('name') ?? '',
                    'is_active' => (bool)$rule->getData('is_active'),
                    'discount_amount' => round((float)($rule->getData('discount_amount') ?? 0), 2),
                    'discount_type' => $discountTypeMap[$simpleAction] ?? $simpleAction,
                    'from_date' => $rule->getData('from_date') ?: null,
                    'to_date' => $rule->getData('to_date') ?: null,
                    'sort_order' => (int)($rule->getData('sort_order') ?? 0),
                    'admin_url' => $this->secureAdminUrl->getUrl(
                        'catalog_rule/promo_catalog/edit',
                        ['id' => $ruleId]
                    ),
                ];
            }

            return [
                'total_count' => count($rules),
                'rules' => $rules,
            ];
        } catch (\Exception $e) {
            return ['error' => 'Failed to list catalog price rules: ' . $e->getMessage()];
        }
    }
}

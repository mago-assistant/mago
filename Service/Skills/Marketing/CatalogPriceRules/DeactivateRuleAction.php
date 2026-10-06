<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class DeactivateRuleAction implements ActionInterface
{
    public function __construct(
        private readonly CatalogRuleServices $catalogRules,
        private readonly SecureAdminUrl $secureAdminUrl,
        private readonly ErrorReporter $errorReporter
    ) {
    }

    public function getName(): string
    {
        return 'deactivate_rule';
    }

    public function getDescription(): string
    {
        return 'Deactivate a catalog price rule';
    }

    public function getParameterSchema(): array
    {
        return [
            'rule_id' => [
                'type' => 'integer',
                'description' => 'The catalog price rule ID to deactivate',
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
            'message' => [PiiClass::PUBLIC],
            'success' => [PiiClass::PUBLIC],
            'rule_id' => [PiiClass::PUBLIC],
            'name' => [PiiClass::PUBLIC],
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
            return ['error' => 'rule_id parameter is required for deactivate_rule'];
        }

        try {
            $rule = $this->catalogRules->getRepository()->get($ruleId);
            $ruleName = $rule->getData('name') ?? '';

            $rule->setData('is_active', 0);
            $this->catalogRules->getRepository()->save($rule);

            return [
                'success' => true,
                'rule_id' => $ruleId,
                'name' => $ruleName,
                'message' => 'Catalog price rule "' . $ruleName . '" has been deactivated. '
                    . 'Run apply_rules to apply the changes to product prices.',
                'admin_url' => $this->secureAdminUrl->getUrl(
                    'catalog_rule/promo_catalog/edit',
                    ['id' => $ruleId]
                ),
            ];
        } catch (\Exception $e) {
            $error = $this->errorReporter->reportToolFailure('catalog_price_rules deactivate_rule', $e);
            return ['error' => 'Failed to deactivate catalog price rule: ' . $error];
        }
    }
}

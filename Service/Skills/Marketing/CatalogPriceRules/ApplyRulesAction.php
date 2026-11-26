<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules;

use Magento\CatalogRule\Model\Rule;
use Magento\Framework\Exception\NoSuchEntityException;
use MagoAssistant\Mago\Api\Skill\CautionedActionInterface;
use MagoAssistant\Mago\Api\Skill\ValidatingActionInterface;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * With a rule_id, recalculates the prices of the products that rule reaches, right away. Without
 * one it does what the admin's "Apply Rules" button does: mark every rule for a full reindex,
 * which cron runs, so prices change on its next run rather than now.
 */
class ApplyRulesAction implements CautionedActionInterface, ValidatingActionInterface
{
    public function __construct(
        private readonly CatalogRuleServices $catalogRules,
        private readonly ErrorReporter $errorReporter,
        private readonly RulePriceReindexer $reindexer
    ) {
    }

    public function getName(): string
    {
        return 'apply_rules';
    }

    public function getDescription(): string
    {
        return 'Recalculate product prices from catalog price rules: pass rule_id to recalculate the '
            . 'products of that rule right away, or nothing to mark every rule for a full reindex on the next '
            . 'cron run';
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
        return false;
    }

    public function getFieldClassification(): array
    {
        return [
            'message' => [PiiClass::PUBLIC],
            'success' => [PiiClass::PUBLIC],
            'rule_id' => [PiiClass::PUBLIC],
            'name' => [PiiClass::PUBLIC],
            'product_count' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return 'apply_rules: after a rule was created, updated or deactivated, pass its rule_id so its '
            . 'prices change right away. Without rule_id every rule is only marked for a full reindex that cron '
            . 'runs; say that prices change on the next cron run, not now.';
    }

    public function getCautions(array $params, int $adminUserId): array
    {
        if (!$this->hasRuleId($params)) {
            return [$this->describeFullReindex()];
        }

        try {
            $rule = $this->loadRule($params);
        } catch (NoSuchEntityException) {
            return [];
        }
        $count = count($this->reindexer->findAffectedProductIds($rule));

        return [
            'Recalculates now the price of the ' . $count . ' ' . ($count === 1 ? 'product' : 'products')
                . ' catalog price rule "' . $rule->getData('name') . '" (ID ' . (int)$rule->getId()
                . ') reaches or reached, and refreshes their cached pages.',
        ];
    }

    public function findRefusal(array $params): ?array
    {
        if (!$this->hasRuleId($params)) {
            return null;
        }

        try {
            $this->loadRule($params);
        } catch (NoSuchEntityException $e) {
            return ['error' => $e->getMessage()];
        }

        return null;
    }

    public function execute(array $params, int $adminUserId): array
    {
        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $refusal = $this->findRefusal($params);
        if ($refusal !== null) {
            return $refusal;
        }

        try {
            return $this->hasRuleId($params) ? $this->applyRule($this->loadRule($params)) : $this->applyAll();
        } catch (\Exception $e) {
            $error = $this->errorReporter->reportToolFailure('catalog_price_rules apply_rules', $e);
            return ['error' => 'Failed to apply catalog price rules: ' . $error];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function applyRule(Rule $rule): array
    {
        $count = $this->reindexer->reindex($rule);

        return [
            'success' => true,
            'rule_id' => (int)$rule->getId(),
            'name' => (string)$rule->getData('name'),
            'product_count' => $count,
            'message' => $count === 0
                ? 'Catalog price rule "' . $rule->getData('name') . '" reaches no products, so no price changed.'
                : 'Prices recalculated for the ' . $count . ' ' . ($count === 1 ? 'product' : 'products')
                    . ' of catalog price rule "' . $rule->getData('name') . '"; the storefront shows them now.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function applyAll(): array
    {
        $this->catalogRules->getJob()->applyAll();

        return [
            'success' => true,
            'message' => 'Every catalog price rule is marked for a full reindex. Prices change when cron next '
                . 'runs the catalog rule indexer, not right away.',
        ];
    }

    private function describeFullReindex(): string
    {
        $count = $this->catalogRules->countActiveRules();

        return 'Marks all ' . $count . ' active catalog price ' . ($count === 1 ? 'rule' : 'rules')
            . ' for a full reindex: cron recalculates the price of every product on its next run, not right away.';
    }

    /**
     * @param array<string, mixed> $params
     */
    private function hasRuleId(array $params): bool
    {
        return (int)($params['rule_id'] ?? 0) !== 0;
    }

    /**
     * @param array<string, mixed> $params
     * @throws NoSuchEntityException
     */
    private function loadRule(array $params): Rule
    {
        $rule = $this->catalogRules->getRepository()->get((int)$params['rule_id']);
        if (!$rule instanceof Rule) {
            throw new NoSuchEntityException(__('The catalog price rule could not be loaded.'));
        }

        return $rule;
    }
}

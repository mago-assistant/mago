<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules;

use MagoAssistant\Mago\Service\Error\ErrorReporter;

/**
 * What create_rule and update_rule say about a rule's conditions before it is saved: a refusal
 * when they cannot be saved as asked or match no product, and otherwise the card lines naming the
 * conditions and how many products they reach, with a few examples.
 */
class RuleConditionReview
{
    public function __construct(
        private readonly RuleConditions $ruleConditions,
        private readonly MatchingProducts $matchingProducts,
        private readonly ErrorReporter $errorReporter
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @param int[] $websiteIds The websites the rule will apply on
     * @return array<string, mixed>|null The refusal, or null when the conditions can be saved
     */
    public function findRefusal(array $params, array $websiteIds, string $toolName): ?array
    {
        if (!$this->ruleConditions->hasConditions($params)) {
            return null;
        }

        try {
            $conditions = $this->ruleConditions->build($params);
            if ($conditions->isEmpty() || !$this->matchingProducts->find($conditions, $websiteIds)->isEmpty()) {
                return null;
            }
        } catch (InvalidRuleConditionException $e) {
            return ['error' => $e->getMessage()];
        } catch (\Exception $e) {
            return ['error' => 'Could not check the rule conditions: '
                . $this->errorReporter->reportToolFailure($toolName, $e)];
        }

        return [
            'error' => 'These conditions match no products on the rule\'s websites right now, so the rule would '
                . 'change no price. Check the values with the administrator before trying again.',
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @param int[] $websiteIds
     * @return string[]
     */
    public function describe(array $params, array $websiteIds): array
    {
        if (!$this->ruleConditions->hasConditions($params)) {
            return [];
        }

        try {
            $conditions = $this->ruleConditions->build($params);
        } catch (InvalidRuleConditionException $e) {
            return ['The conditions cannot be saved as asked: ' . $e->getMessage()];
        }
        if ($conditions->isEmpty()) {
            return [];
        }

        return array_values(array_filter([
            $conditions->describe(),
            $this->matchingProducts->find($conditions, $websiteIds)->describe(),
        ]));
    }

    /**
     * @param array<string, mixed> $params
     * @throws InvalidRuleConditionException
     */
    public function build(array $params): RuleConditionSet
    {
        return $this->ruleConditions->build($params);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function hasConditions(array $params): bool
    {
        return $this->ruleConditions->hasConditions($params);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function restrictsProducts(array $params): bool
    {
        try {
            return !$this->ruleConditions->build($params)->isEmpty();
        } catch (InvalidRuleConditionException) {
            return true;
        }
    }
}

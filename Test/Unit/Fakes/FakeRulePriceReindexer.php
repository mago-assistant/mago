<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\CatalogRule\Model\Rule;
use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\RulePriceReindexer;

/**
 * Indexers in one mode, the given affected products for every rule, and a record of the rules it
 * was asked to reindex
 */
class FakeRulePriceReindexer extends RulePriceReindexer
{
    /** @var int[] */
    private array $reindexedRuleIds = [];

    /**
     * @param int[] $affectedProductIds
     */
    public function __construct(
        private readonly bool $isAppliedOnSave = false,
        private readonly array $affectedProductIds = []
    ) {
    }

    public function isAppliedOnSave(): bool
    {
        return $this->isAppliedOnSave;
    }

    public function findAffectedProductIds(Rule $rule): array
    {
        return $this->affectedProductIds;
    }

    public function reindex(Rule $rule): int
    {
        $this->reindexedRuleIds[] = (int)$rule->getId();

        return count($this->affectedProductIds);
    }

    /**
     * @return int[]
     */
    public function reindexedRuleIds(): array
    {
        return $this->reindexedRuleIds;
    }
}

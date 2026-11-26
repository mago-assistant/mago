<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\CatalogRule\Api\CatalogRuleRepositoryInterface;
use Magento\CatalogRule\Api\Data\RuleInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Catalog rules in memory by id; savedRules() lists every save in order.
 */
final class FakeCatalogRuleRepository implements CatalogRuleRepositoryInterface
{
    /** @var array<int, RuleInterface> */
    private array $rules = [];

    /** @var RuleInterface[] */
    private array $saved = [];

    public function withRule(int $ruleId, RuleInterface $rule): self
    {
        $this->rules[$ruleId] = $rule;

        return $this;
    }

    /**
     * @return RuleInterface[]
     */
    public function savedRules(): array
    {
        return $this->saved;
    }

    public function save(RuleInterface $rule): RuleInterface
    {
        $this->saved[] = $rule;

        return $rule;
    }

    public function get($ruleId): RuleInterface
    {
        return $this->rules[(int)$ruleId]
            ?? throw new NoSuchEntityException(__('The rule with the "%1" ID wasn\'t found.', $ruleId));
    }

    public function delete(RuleInterface $rule): bool
    {
        return true;
    }

    public function deleteById($ruleId): bool
    {
        unset($this->rules[(int)$ruleId]);

        return true;
    }
}

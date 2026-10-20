<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\CatalogRule\Api\CatalogRuleRepositoryInterface;
use Magento\CatalogRule\Model\Rule;
use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\CatalogRuleServices;

/**
 * Hands out a given repository, rule and active rule count instead of resolving them through the
 * object manager.
 */
class FakeCatalogRuleServices extends CatalogRuleServices
{
    public function __construct(
        private readonly CatalogRuleRepositoryInterface $repository,
        private readonly ?Rule $rule = null,
        private readonly int $activeRuleCount = 0
    ) {
    }

    public function countActiveRules(): int
    {
        return $this->activeRuleCount;
    }

    public function getRepository(): CatalogRuleRepositoryInterface
    {
        return $this->repository;
    }

    public function createRule(): Rule
    {
        return $this->rule ?? throw new \LogicException('No rule was given to this fake');
    }
}

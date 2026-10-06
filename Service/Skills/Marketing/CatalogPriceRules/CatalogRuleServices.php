<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules;

use Magento\CatalogRule\Api\CatalogRuleRepositoryInterface;
use Magento\CatalogRule\Model\ResourceModel\Rule\Collection;
use Magento\CatalogRule\Model\Rule;
use Magento\CatalogRule\Model\Rule\Job;
use Magento\Framework\ObjectManagerInterface;

/**
 * The Magento_CatalogRule services, resolved when an action runs rather than injected. Stores remove
 * the CatalogRule module, and a constructor dependency on it would then break the whole tool
 * registry, which every admin page builds, and setup:di:compile. CatalogPriceRules is unavailable
 * without the module, so these are only reached when it is installed.
 */
class CatalogRuleServices
{
    public function __construct(
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    public function getRepository(): CatalogRuleRepositoryInterface
    {
        return $this->objectManager->get(CatalogRuleRepositoryInterface::class);
    }

    public function createRule(): Rule
    {
        return $this->objectManager->create(Rule::class);
    }

    public function createCollection(): Collection
    {
        return $this->objectManager->create(Collection::class);
    }

    public function getJob(): Job
    {
        return $this->objectManager->get(Job::class);
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\MatchingProducts;
use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\ProductMatches;
use MagoAssistant\Mago\Service\Skills\Marketing\CatalogPriceRules\RuleConditionSet;

/**
 * Answers every match with the given products and remembers the websites it was asked about
 */
class FakeMatchingProducts extends MatchingProducts
{
    /** @var int[][] */
    private array $askedWebsiteIds = [];

    public function __construct(
        private readonly ProductMatches $matches = new ProductMatches(0, [])
    ) {
    }

    public function find(RuleConditionSet $conditions, array $websiteIds): ProductMatches
    {
        $this->askedWebsiteIds[] = $websiteIds;

        return $this->matches;
    }

    /**
     * @return int[][]
     */
    public function askedWebsiteIds(): array
    {
        return $this->askedWebsiteIds;
    }
}

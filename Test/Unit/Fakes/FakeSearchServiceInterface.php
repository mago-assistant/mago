<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Framework\Api\SearchCriteriaInterface;

/**
 * A repository as Magento's service contracts declare a search.
 */
interface FakeSearchServiceInterface
{
    /**
     * @return array<int, mixed>
     */
    public function getList(SearchCriteriaInterface $searchCriteria): array;
}

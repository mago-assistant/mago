<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Api\InProcess;

/**
 * Gives a search past the last page no items, as REST does. Outside webapi_rest Magento's
 * Magento\Theme\Plugin\Data\Collection resets a current page beyond the last one to page 1, so in-process
 * a getList route would otherwise answer page 999 with the items of page 1.
 */
class PastLastPageNormalizer
{
    private const SEARCH_CRITERIA = 'search_criteria';
    private const CURRENT_PAGE = 'current_page';
    private const PAGE_SIZE = 'page_size';
    private const TOTAL_COUNT = 'total_count';
    private const ITEMS = 'items';
    private const FIRST_PAGE = 1;

    /**
     * @param array<array-key, mixed> $output
     * @return array<array-key, mixed>
     */
    public function normalize(array $output): array
    {
        if (!$this->isPastLastPage($output)) {
            return $output;
        }

        return array_replace($output, [self::ITEMS => []]);
    }

    /**
     * @param array<array-key, mixed> $output
     */
    private function isPastLastPage(array $output): bool
    {
        $searchCriteria = is_array($output[self::SEARCH_CRITERIA] ?? null) ? $output[self::SEARCH_CRITERIA] : [];
        $currentPage = $this->toInt($searchCriteria[self::CURRENT_PAGE] ?? null);
        $pageSize = $this->toInt($searchCriteria[self::PAGE_SIZE] ?? null);
        $totalCount = $this->toInt($output[self::TOTAL_COUNT] ?? null);

        if ($currentPage === null || $pageSize === null || $totalCount === null || $pageSize <= 0) {
            return false;
        }

        return $currentPage > $this->getLastPage($totalCount, $pageSize);
    }

    private function getLastPage(int $totalCount, int $pageSize): int
    {
        return max(self::FIRST_PAGE, (int)ceil($totalCount / $pageSize));
    }

    private function toInt(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int)$value : null;
    }
}

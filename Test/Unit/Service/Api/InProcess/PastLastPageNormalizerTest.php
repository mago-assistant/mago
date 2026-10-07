<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Api\InProcess;

use MagoAssistant\Mago\Service\Api\InProcess\PastLastPageNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PastLastPageNormalizerTest extends TestCase
{
    private const ITEMS = [['sku' => 'mug'], ['sku' => 'cup']];

    #[Test]
    public function itKeepsTheItemsOfExactlyTheLastPage(): void
    {
        $output = $this->searchResult(currentPage: 3, pageSize: 2, totalCount: 6);

        $result = (new PastLastPageNormalizer())->normalize($output);

        self::assertSame($output, $result);
    }

    #[Test]
    public function itEmptiesTheItemsOfThePageAfterTheLastOne(): void
    {
        $output = $this->searchResult(currentPage: 4, pageSize: 2, totalCount: 6);

        $result = (new PastLastPageNormalizer())->normalize($output);

        self::assertSame([], $result['items']);
        self::assertSame(6, $result['total_count']);
        self::assertSame($output['search_criteria'], $result['search_criteria']);
    }

    #[Test]
    public function itKeepsTheItemsOfAPartlyFilledLastPage(): void
    {
        $output = $this->searchResult(currentPage: 4, pageSize: 2, totalCount: 7);

        $result = (new PastLastPageNormalizer())->normalize($output);

        self::assertSame(self::ITEMS, $result['items']);
    }

    #[Test]
    public function itEmptiesTheItemsOfPageTwoWhenNothingMatches(): void
    {
        $output = $this->searchResult(currentPage: 2, pageSize: 20, totalCount: 0);

        $result = (new PastLastPageNormalizer())->normalize($output);

        self::assertSame([], $result['items']);
    }

    #[Test]
    public function itLeavesPageOneAloneWhenNothingMatches(): void
    {
        $output = $this->searchResult(currentPage: 1, pageSize: 20, totalCount: 0);

        $result = (new PastLastPageNormalizer())->normalize($output);

        self::assertSame($output, $result);
    }

    #[Test]
    public function itLeavesPageOneAloneWhenItHoldsFewerItemsThanThePageSize(): void
    {
        $output = $this->searchResult(currentPage: 1, pageSize: 20, totalCount: 2);

        $result = (new PastLastPageNormalizer())->normalize($output);

        self::assertSame($output, $result);
    }

    #[Test]
    public function itLeavesAnOutputWithoutSearchCriteriaAlone(): void
    {
        $output = ['items' => self::ITEMS, 'total_count' => 2];

        $result = (new PastLastPageNormalizer())->normalize($output);

        self::assertSame($output, $result);
    }

    #[Test]
    public function itLeavesASearchWithoutAPageSizeAlone(): void
    {
        $output = $this->searchResult(currentPage: 5, pageSize: null, totalCount: 2);

        $result = (new PastLastPageNormalizer())->normalize($output);

        self::assertSame($output, $result);
    }

    #[Test]
    public function itLeavesASearchWithAPageSizeOfZeroAlone(): void
    {
        $output = $this->searchResult(currentPage: 5, pageSize: 0, totalCount: 2);

        $result = (new PastLastPageNormalizer())->normalize($output);

        self::assertSame($output, $result);
    }

    #[Test]
    public function itLeavesAScalarResultAlone(): void
    {
        $result = (new PastLastPageNormalizer())->normalize(['result' => true]);

        self::assertSame(['result' => true], $result);
    }

    /**
     * @return array<string, mixed>
     */
    private function searchResult(int $currentPage, ?int $pageSize, int $totalCount): array
    {
        return [
            'items' => self::ITEMS,
            'search_criteria' => ['filter_groups' => [], 'page_size' => $pageSize, 'current_page' => $currentPage],
            'total_count' => $totalCount,
        ];
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Ui\DataProvider;

use Magento\Framework\Api\Filter;
use Magento\Framework\Api\Search\FilterGroup;
use Magento\Framework\Api\Search\SearchCriteria;
use Magento\Framework\Api\SortOrder;
use MagoAssistant\Mago\Ui\DataProvider\ArrayGridQuery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #307: the skills grid sorts, filters and pages its in-memory rows
 */
final class ArrayGridQueryTest extends TestCase
{
    private const ROWS = [
        ['name' => 'config_reader', 'description' => 'Read store configuration.', 'category' => 'Configuration', 'type' => 'read'],
        ['name' => 'cache_manager', 'description' => 'Manage Magento caches.', 'category' => 'Configuration', 'type' => 'write'],
        ['name' => 'sales_data', 'description' => 'Sales reports over a period.', 'category' => 'Sales', 'type' => 'read'],
        ['name' => 'product_data', 'description' => 'Query product catalog data.', 'category' => 'Catalog', 'type' => 'read'],
        ['name' => 'admin_navigator', 'description' => 'Find admin pages.', 'category' => 'Navigation', 'type' => 'read'],
    ];
    private const KEYWORD_FIELDS = ['name', 'description', 'category'];

    private ArrayGridQuery $query;

    protected function setUp(): void
    {
        $this->query = new ArrayGridQuery();
    }

    #[Test]
    public function itReturnsEveryRowInOriginalOrderWithoutCriteria(): void
    {
        $result = $this->applyQuery(self::ROWS, new SearchCriteria());

        self::assertSame(5, $result['totalRecords']);
        self::assertSame(self::ROWS, $result['items']);
    }

    #[Test]
    public function itSortsByNameAscending(): void
    {
        $result = $this->applyQuery(self::ROWS, $this->criteria([], [$this->sort('name', 'ASC')]));

        self::assertSame(
            ['admin_navigator', 'cache_manager', 'config_reader', 'product_data', 'sales_data'],
            array_column($result['items'], 'name')
        );
    }

    #[Test]
    public function itSortsByNameDescending(): void
    {
        $result = $this->applyQuery(self::ROWS, $this->criteria([], [$this->sort('name', 'DESC')]));

        self::assertSame(
            ['sales_data', 'product_data', 'config_reader', 'cache_manager', 'admin_navigator'],
            array_column($result['items'], 'name')
        );
    }

    #[Test]
    public function itBreaksTiesWithTheNextSortOrder(): void
    {
        $result = $this->applyQuery(
            self::ROWS,
            $this->criteria([], [$this->sort('category', 'ASC'), $this->sort('name', 'DESC')])
        );

        self::assertSame(
            ['product_data', 'config_reader', 'cache_manager', 'admin_navigator', 'sales_data'],
            array_column($result['items'], 'name')
        );
    }

    #[Test]
    public function itFiltersATextColumnCaseInsensitively(): void
    {
        $result = $this->applyQuery(self::ROWS, $this->criteria([[$this->filter('description', '%MAGENTO%', 'like')]]));

        self::assertSame(['cache_manager'], array_column($result['items'], 'name'));
    }

    #[Test]
    public function itTreatsAnEscapedUnderscoreInALikeFilterAsALiteral(): void
    {
        $rows = [['name' => 'cache_manager'], ['name' => 'cachexmanager']];

        $result = $this->applyQuery($rows, $this->criteria([[$this->filter('name', '%cache\_manager%', 'like')]]));

        self::assertSame(['cache_manager'], array_column($result['items'], 'name'));
    }

    #[Test]
    public function itFiltersASelectColumnOnExactValue(): void
    {
        $result = $this->applyQuery(self::ROWS, $this->criteria([[$this->filter('type', 'write', 'eq')]]));

        self::assertSame(['cache_manager'], array_column($result['items'], 'name'));
    }

    #[Test]
    public function itFiltersOnAnyOfSeveralValues(): void
    {
        $result = $this->applyQuery(self::ROWS, $this->criteria([[$this->filter('category', ['Sales', 'Catalog'], 'in')]]));

        self::assertSame(['sales_data', 'product_data'], array_column($result['items'], 'name'));
    }

    #[Test]
    public function itCombinesFilterGroupsWithAnd(): void
    {
        $result = $this->applyQuery(self::ROWS, $this->criteria([
            [$this->filter('category', 'Configuration', 'eq')],
            [$this->filter('type', 'read', 'eq')],
        ]));

        self::assertSame(['config_reader'], array_column($result['items'], 'name'));
    }

    #[Test]
    public function itMatchesAKeywordSearchAgainstTheKeywordColumns(): void
    {
        $result = $this->applyQuery(self::ROWS, $this->criteria([[$this->filter('fulltext', 'catalog', 'fulltext')]]));

        self::assertSame(['product_data'], array_column($result['items'], 'name'));
    }

    #[Test]
    public function itIgnoresColumnsOutsideTheKeywordColumnsInAKeywordSearch(): void
    {
        $result = $this->applyQuery(self::ROWS, $this->criteria([[$this->filter('fulltext', 'write', 'fulltext')]]));

        self::assertSame([], $result['items']);
    }

    #[Test]
    public function itKeepsTheOriginalOrderForTheSortOrderWithoutAFieldThatCoreSends(): void
    {
        $result = $this->applyQuery(self::ROWS, $this->criteria([], [new SortOrder()]));

        self::assertSame(self::ROWS, $result['items']);
    }

    #[Test]
    public function itReturnsTheRequestedPageAndCountsAllMatches(): void
    {
        $criteria = $this->criteria([], [$this->sort('name', 'ASC')])->setPageSize(2)->setCurrentPage(2);

        $result = $this->applyQuery(self::ROWS, $criteria);

        self::assertSame(5, $result['totalRecords']);
        self::assertSame(['config_reader', 'product_data'], array_column($result['items'], 'name'));
    }

    #[Test]
    public function itRefusesAConditionItDoesNotKnow(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->applyQuery(self::ROWS, $this->criteria([[$this->filter('name', 'a', 'gteq')]]));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{totalRecords: int, items: list<array<string, mixed>>}
     */
    private function applyQuery(array $rows, SearchCriteria $criteria): array
    {
        return $this->query->apply($rows, $criteria, self::KEYWORD_FIELDS);
    }

    /**
     * @param list<list<Filter>> $filterGroups
     * @param list<SortOrder> $sortOrders
     */
    private function criteria(array $filterGroups, array $sortOrders = []): SearchCriteria
    {
        return (new SearchCriteria())
            ->setFilterGroups(array_map(
                static fn (array $filters): FilterGroup => (new FilterGroup())->setFilters($filters),
                $filterGroups
            ))
            ->setSortOrders($sortOrders);
    }

    /**
     * @param string|string[] $value
     */
    private function filter(string $field, string|array $value, string $conditionType): Filter
    {
        return (new Filter())->setField($field)->setValue($value)->setConditionType($conditionType);
    }

    private function sort(string $field, string $direction): SortOrder
    {
        return (new SortOrder())->setField($field)->setDirection($direction);
    }
}

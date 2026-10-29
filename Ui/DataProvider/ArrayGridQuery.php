<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Ui\DataProvider;

use Magento\Framework\Api\Filter;
use Magento\Framework\Api\Search\FilterGroup;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SortOrder;

/**
 * Applies a grid's filters, sort orders and page to rows that live in memory rather than in a table
 * (#307), the way the UI listing components would expect from a collection
 */
final class ArrayGridQuery
{
    private const CONDITION_LIKE = 'like';
    private const CONDITION_EQUALS = 'eq';
    private const CONDITION_IN = 'in';
    private const CONDITION_FULLTEXT = 'fulltext';

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{totalRecords: int, items: list<array<string, mixed>>}
     */
    public function apply(array $rows, SearchCriteriaInterface $criteria): array
    {
        $matching = array_values(array_filter(
            $rows,
            fn (array $row): bool => $this->matchesEveryGroup($row, $criteria->getFilterGroups() ?? [])
        ));
        $sorted = $this->sort($matching, $criteria->getSortOrders() ?? []);

        return [
            'totalRecords' => count($sorted),
            'items' => $this->page($sorted, $criteria->getPageSize(), $criteria->getCurrentPage()),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param FilterGroup[] $groups
     */
    private function matchesEveryGroup(array $row, array $groups): bool
    {
        foreach ($groups as $group) {
            if (!$this->matchesAnyFilter($row, $group->getFilters() ?? [])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     * @param Filter[] $filters
     */
    private function matchesAnyFilter(array $row, array $filters): bool
    {
        if ($filters === []) {
            return true;
        }
        foreach ($filters as $filter) {
            if ($this->matches($row, $filter)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function matches(array $row, Filter $filter): bool
    {
        $value = $filter->getValue();
        $cell = (string)($row[$filter->getField()] ?? '');

        return match ($filter->getConditionType() ?? self::CONDITION_EQUALS) {
            self::CONDITION_LIKE => preg_match($this->likeToRegex((string)$value), $cell) === 1,
            self::CONDITION_EQUALS => $cell === (string)$value,
            self::CONDITION_IN => in_array($cell, array_map('strval', (array)$value), true),
            self::CONDITION_FULLTEXT => $this->containsKeyword($row, (string)$value),
            default => throw new \InvalidArgumentException(
                'Unsupported grid filter condition: ' . $filter->getConditionType()
            ),
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function containsKeyword(array $row, string $keyword): bool
    {
        $cells = array_filter($row, 'is_scalar');

        return array_filter($cells, static fn ($cell): bool => mb_stripos((string)$cell, $keyword) !== false) !== [];
    }

    /**
     * A SQL LIKE pattern as a case-insensitive regex: % and _ are wildcards unless escaped with a backslash
     */
    private function likeToRegex(string $pattern): string
    {
        $tokens = preg_split('/(\\\\.|%|_)/su', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];

        return '/^' . implode('', array_map(
            static fn (string $token): string => match (true) {
                $token === '%' => '.*',
                $token === '_' => '.',
                strlen($token) > 1 && $token[0] === '\\' => preg_quote(substr($token, 1), '/'),
                default => preg_quote($token, '/'),
            },
            $tokens
        )) . '$/isu';
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param SortOrder[] $sortOrders
     * @return list<array<string, mixed>>
     */
    private function sort(array $rows, array $sortOrders): array
    {
        if ($sortOrders === []) {
            return $rows;
        }

        usort($rows, static function (array $left, array $right) use ($sortOrders): int {
            foreach ($sortOrders as $sortOrder) {
                $field = (string)$sortOrder->getField();
                $comparison = strnatcasecmp((string)($left[$field] ?? ''), (string)($right[$field] ?? ''));
                if ($comparison !== 0) {
                    return $sortOrder->getDirection() === SortOrder::SORT_DESC ? -$comparison : $comparison;
                }
            }

            return 0;
        });

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function page(array $rows, ?int $pageSize, ?int $currentPage): array
    {
        if ($pageSize === null || $pageSize < 1) {
            return $rows;
        }

        return array_slice($rows, (max(1, (int)$currentPage) - 1) * $pageSize, $pageSize);
    }
}

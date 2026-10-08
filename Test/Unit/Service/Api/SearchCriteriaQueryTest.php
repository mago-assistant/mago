<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Api;

use MagoAssistant\Mago\Service\Api\SearchCriteriaQuery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SearchCriteriaQueryTest extends TestCase
{
    #[Test]
    public function aSingleFilterIsItsOwnGroup(): void
    {
        $params = (new SearchCriteriaQuery())->build(
            [
                ['field' => 'status', 'value' => '1', 'condition_type' => 'eq'],
                ['field' => 'name', 'value' => '%a%', 'condition_type' => 'like'],
            ],
            20,
            1,
            null
        );

        self::assertSame('status', $params['searchCriteria[filter_groups][0][filters][0][field]']);
        self::assertSame('name', $params['searchCriteria[filter_groups][1][filters][0][field]']);
        self::assertSame('like', $params['searchCriteria[filter_groups][1][filters][0][conditionType]']);
        self::assertArrayNotHasKey('searchCriteria[filter_groups][0][filters][1][field]', $params);
        self::assertSame(20, $params['searchCriteria[pageSize]']);
    }

    #[Test]
    public function aListOfFiltersIsOneGroup(): void
    {
        $params = (new SearchCriteriaQuery())->build(
            [[
                ['field' => 'firstname', 'value' => '%jan%', 'condition_type' => 'like'],
                ['field' => 'lastname', 'value' => '%jan%'],
            ]],
            10,
            1,
            [['field' => 'created_at', 'direction' => 'DESC']]
        );

        self::assertSame('firstname', $params['searchCriteria[filter_groups][0][filters][0][field]']);
        self::assertSame('lastname', $params['searchCriteria[filter_groups][0][filters][1][field]']);
        self::assertSame('%jan%', $params['searchCriteria[filter_groups][0][filters][1][value]']);
        self::assertArrayNotHasKey('searchCriteria[filter_groups][0][filters][1][conditionType]', $params);
        self::assertArrayNotHasKey('searchCriteria[filter_groups][1][filters][0][field]', $params);
        self::assertSame('created_at', $params['searchCriteria[sortOrders][0][field]']);
    }
}

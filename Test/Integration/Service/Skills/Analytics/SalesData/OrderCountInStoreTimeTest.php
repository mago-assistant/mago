<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Integration\Service\Skills\Analytics\SalesData;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use MagoAssistant\Mago\Service\Skills\Analytics\SalesData\OrderCountAction;
use MagoAssistant\Mago\Service\Skills\PeriodParser;
use MagoAssistant\Mago\Service\Time\CalendarBuckets;
use MagoAssistant\Mago\Service\Time\StoreTime;
use MagoAssistant\Mago\Test\Integration\MagentoObjectManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The buckets of a count over time are the store's years and months, the same clock the period is
 * read on, so an order counted in October is not reported under September.
 */
final class OrderCountInStoreTimeTest extends TestCase
{
    private const TIMEZONE = 'Europe/Amsterdam';
    private const ADMIN_USER_ID = 1;

    private ResourceConnection $resourceConnection;
    private OrderCountAction $action;

    protected function setUp(): void
    {
        $this->resourceConnection = MagentoObjectManager::get()->get(ResourceConnection::class);
        $config = $this->createStub(TimezoneInterface::class);
        $config->method('getConfigTimezone')->willReturn(self::TIMEZONE);
        $storeTime = new StoreTime($config);
        $this->action = new OrderCountAction(
            $this->resourceConnection,
            new PeriodParser($storeTime),
            new CalendarBuckets($storeTime)
        );
        $this->resourceConnection->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->resourceConnection->getConnection()->rollBack();
    }

    #[Test]
    public function anOrderJustAfterLocalMidnightCountsInTheMonthItsPeriodSays(): void
    {
        $this->orderAt('2001-09-30 22:30:00');

        $result = $this->action->execute(['period' => '2001-10', 'group_by' => 'month'], self::ADMIN_USER_ID);

        self::assertSame(['2001-10' => 1], $result['counts']);
    }

    #[Test]
    public function monthsAreSplitAtLocalMidnightOnEitherSideOfDaylightSaving(): void
    {
        $this->orderAt('2001-09-30 22:30:00');
        $this->orderAt('2001-10-31 22:30:00');
        $this->orderAt('2001-10-31 23:30:00');

        $result = $this->action->execute(
            ['period' => '2001-10-01:2001-11-30', 'group_by' => 'month'],
            self::ADMIN_USER_ID
        );

        self::assertSame(['2001-10' => 2, '2001-11' => 1], $result['counts']);
        self::assertSame(3, $result['total']);
    }

    #[Test]
    public function anOrderJustAfterLocalMidnightOnNewYearCountsInTheNewYear(): void
    {
        $this->orderAt('2001-12-31 23:30:00');
        $this->orderAt('2001-06-01 12:00:00');

        $result = $this->action->execute(
            ['period' => '2001-01-01:2002-12-31', 'group_by' => 'year'],
            self::ADMIN_USER_ID
        );

        self::assertSame([2001 => 1, 2002 => 1], $result['counts']);
    }

    #[Test]
    public function aWindowWithoutOrdersHasNoBuckets(): void
    {
        $result = $this->action->execute(['period' => '2001-10', 'group_by' => 'month'], self::ADMIN_USER_ID);

        self::assertSame([], $result['counts']);
        self::assertSame(0, $result['total']);
    }

    private function orderAt(string $utcCreatedAt): void
    {
        (new SalesOrderFixture($this->resourceConnection, $utcCreatedAt))->invoicedOrder(10.00, 0.0, 0.0);
    }
}

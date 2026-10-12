<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Time;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use MagoAssistant\Mago\Service\Time\CalendarBuckets;
use MagoAssistant\Mago\Service\Time\StoreTime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CalendarBucketsTest extends TestCase
{
    #[Test]
    public function anOrderJustAfterLocalMidnightOnTheFirstStartsTheStoresMonth(): void
    {
        $buckets = $this->bucketsIn('Europe/Amsterdam');

        $months = $buckets->months('2026-09-30 22:30:00', '2026-10-20 10:00:00');

        self::assertSame([['label' => '2026-10', 'from' => '2026-09-30 22:00:00']], $months);
    }

    #[Test]
    public function eachMonthStartsAtLocalMidnightOnEitherSideOfDaylightSaving(): void
    {
        $buckets = $this->bucketsIn('Europe/Amsterdam');

        $months = $buckets->months('2026-10-15 12:00:00', '2026-10-31 23:30:00');

        self::assertSame([
            ['label' => '2026-10', 'from' => '2026-09-30 22:00:00'],
            ['label' => '2026-11', 'from' => '2026-10-31 23:00:00'],
        ], $months);
    }

    #[Test]
    public function anOrderJustAfterLocalMidnightOnNewYearStartsTheStoresYear(): void
    {
        $buckets = $this->bucketsIn('Europe/Amsterdam');

        $years = $buckets->years('2024-06-01 10:00:00', '2025-12-31 23:30:00');

        self::assertSame([
            ['label' => '2024', 'from' => '2023-12-31 23:00:00'],
            ['label' => '2025', 'from' => '2024-12-31 23:00:00'],
            ['label' => '2026', 'from' => '2025-12-31 23:00:00'],
        ], $years);
    }

    #[Test]
    public function aStoreWestOfUtcStartsItsMonthsLater(): void
    {
        $buckets = $this->bucketsIn('America/New_York');

        $months = $buckets->months('2026-03-01 03:00:00', '2026-04-01 04:30:00');

        self::assertSame([
            ['label' => '2026-02', 'from' => '2026-02-01 05:00:00'],
            ['label' => '2026-03', 'from' => '2026-03-01 05:00:00'],
            ['label' => '2026-04', 'from' => '2026-04-01 04:00:00'],
        ], $months);
    }

    #[Test]
    public function aUtcStoreBucketsOnTheStoredTimestamps(): void
    {
        $buckets = $this->bucketsIn('UTC');

        $months = $buckets->months('2026-01-31 23:59:59', '2026-02-01 00:00:00');

        self::assertSame([
            ['label' => '2026-01', 'from' => '2026-01-01 00:00:00'],
            ['label' => '2026-02', 'from' => '2026-02-01 00:00:00'],
        ], $months);
    }

    #[Test]
    public function aSingleTimestampIsASingleBucket(): void
    {
        $buckets = $this->bucketsIn('Europe/Amsterdam');

        $years = $buckets->years('2026-10-09 21:59:59', '2026-10-09 21:59:59');

        self::assertSame([['label' => '2026', 'from' => '2025-12-31 23:00:00']], $years);
    }

    private function bucketsIn(string $timezone): CalendarBuckets
    {
        $config = $this->createStub(TimezoneInterface::class);
        $config->method('getConfigTimezone')->willReturn($timezone);

        return new CalendarBuckets(new StoreTime($config));
    }
}

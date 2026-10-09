<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Time;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use MagoAssistant\Mago\Service\Time\StoreTime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class StoreTimeTest extends TestCase
{
    #[Test]
    public function itConvertsBothWaysAcrossDaylightSaving(): void
    {
        $storeTime = $this->storeTimeIn('Europe/Amsterdam');

        self::assertSame('2026-10-08 00:30:00', $storeTime->toLocal('2026-10-07 22:30:00'));
        self::assertSame('2026-12-08 00:30:00', $storeTime->toLocal('2026-12-07 23:30:00'));
        self::assertSame('2026-10-07 22:30:00', $storeTime->toUtc('2026-10-08 00:30:00'));
    }

    #[Test]
    public function itLeavesAnythingThatIsNoTimestampAlone(): void
    {
        $storeTime = $this->storeTimeIn('Europe/Amsterdam');

        self::assertSame('', $storeTime->toLocal(''));
        self::assertSame('2026-10-07', $storeTime->toLocal('2026-10-07'));
    }

    #[Test]
    public function itFallsBackToUtcWithoutAUsableTimezone(): void
    {
        self::assertSame('2026-10-07 22:30:00', $this->storeTimeIn('')->toLocal('2026-10-07 22:30:00'));
        self::assertSame('2026-10-07 22:30:00', $this->storeTimeIn('Mars/Olympus')->toLocal('2026-10-07 22:30:00'));
        self::assertSame('UTC', $this->storeTimeIn('')->now()->getTimezone()->getName());
    }

    private function storeTimeIn(string $timezone): StoreTime
    {
        $config = $this->createStub(TimezoneInterface::class);
        $config->method('getConfigTimezone')->willReturn($timezone);

        return new StoreTime($config);
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\System\CronStatus;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use MagoAssistant\Mago\Service\Skills\System\CronStatus\ListFailedAction;
use MagoAssistant\Mago\Service\Time\StoreTime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ListFailedActionTimeTest extends TestCase
{
    #[Test]
    public function aJobTimeIsShownOnTheStoresClockAndAMissingOneStaysNull(): void
    {
        $config = $this->createStub(TimezoneInterface::class);
        $config->method('getConfigTimezone')->willReturn('Europe/Amsterdam');
        $action = (new \ReflectionClass(ListFailedAction::class))->newInstanceWithoutConstructor();

        \Closure::bind(function () use ($config): void {
            $this->storeTime = new StoreTime($config);
        }, $action, ListFailedAction::class)();
        $localTime = \Closure::bind(fn (mixed $utc): ?string => $this->localTime($utc), $action, ListFailedAction::class);

        self::assertSame('2026-10-08 00:30:00', $localTime('2026-10-07 22:30:00'));
        self::assertNull($localTime(null));
        self::assertNull($localTime(''));
    }
}

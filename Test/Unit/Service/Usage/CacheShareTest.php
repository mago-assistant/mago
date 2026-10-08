<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Usage;

use MagoAssistant\Mago\Service\Usage\CacheShare;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CacheShareTest extends TestCase
{
    #[Test]
    public function itShowsTheShareOfInputTokensServedFromTheCache(): void
    {
        $share = (new CacheShare())->format(9000, 10000);

        self::assertSame('90%', $share);
    }

    #[Test]
    public function itRoundsToAWholePercentage(): void
    {
        $share = (new CacheShare())->format(2, 3);

        self::assertSame('67%', $share);
    }

    #[Test]
    public function itShowsZeroPercentWhenTheProviderReportedNoCacheHits(): void
    {
        $share = (new CacheShare())->format(0, 10000);

        self::assertSame('0%', $share);
    }

    #[Test]
    public function itShowsNothingWhenTheProviderDidNotReportCacheFigures(): void
    {
        $share = (new CacheShare())->format(null, 10000);

        self::assertSame('', $share);
    }

    #[Test]
    public function itShowsNothingWhenThereAreNoInputTokensToDivideBy(): void
    {
        $cacheShare = new CacheShare();

        $noInput = $cacheShare->format(500, 0);
        $unknownInput = $cacheShare->format(500, null);

        self::assertSame('', $noInput);
        self::assertSame('', $unknownInput);
    }

    #[Test]
    public function itNeverShowsMoreThanAHundredPercent(): void
    {
        $share = (new CacheShare())->format(1600, 1000);

        self::assertSame('100%', $share);
    }

    #[Test]
    public function itAcceptsTheStringsADatabaseRowReturns(): void
    {
        $share = (new CacheShare())->format('450', '1000');

        self::assertSame('45%', $share);
    }
}

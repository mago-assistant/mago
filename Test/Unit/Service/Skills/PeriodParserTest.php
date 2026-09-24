<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills;

use MagoAssistant\Mago\Service\Skills\PeriodParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PeriodParserTest extends TestCase
{
    private PeriodParser $periodParser;

    protected function setUp(): void
    {
        $this->periodParser = new PeriodParser();
    }

    #[Test]
    public function itUnderstandsThePeriodsAModelWritesOutInWords(): void
    {
        foreach (['last week', 'past week', 'last 7 days'] as $period) {
            self::assertSame(
                $this->periodParser->getFromDate('7days'),
                $this->periodParser->getFromDate($period),
                $period
            );
        }
    }

    #[Test]
    public function allReachesBackFurtherThanAnyStoreHasData(): void
    {
        [$from, $to] = $this->periodParser->parse('all');

        self::assertSame('1970-01-01 00:00:00', $from);
        self::assertSame((new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d 23:59:59'), $to);
    }

    #[Test]
    public function theFromDateReadsTheSameVocabularyAsParse(): void
    {
        self::assertSame('1970-01-01 00:00:00', $this->periodParser->getFromDate('all'));
        self::assertSame('2026-01-01 00:00:00', $this->periodParser->getFromDate('2026-01-01:2026-01-31'));
        self::assertSame('2026-08-01 00:00:00', $this->periodParser->getFromDate('2026-08'));
    }

    #[Test]
    public function theFromDateRefusesAPeriodItDoesNotKnow(): void
    {
        // It used to answer about the last thirty days, so a question about a range nobody parsed
        // came back looking answered.
        $this->expectException(\InvalidArgumentException::class);

        $this->periodParser->getFromDate('sinds 2020');
    }

    #[Test]
    public function itParsesAnExplicitDateRange(): void
    {
        [$from, $to] = $this->periodParser->parse('2026-01-01:2026-01-31');

        self::assertSame('2026-01-01 00:00:00', $from);
        self::assertSame('2026-01-31 23:59:59', $to);
    }

    #[Test]
    public function itParsesAWholeYearWrittenAsFourDigits(): void
    {
        [$from, $to] = $this->periodParser->parse('2026');

        self::assertSame('2026-01-01 00:00:00', $from);
        self::assertSame('2026-12-31 23:59:59', $to);
    }

    #[Test]
    public function itParsesASpecificCalendarMonth(): void
    {
        [$from, $to] = $this->periodParser->parse('2026-08');

        self::assertSame('2026-08-01 00:00:00', $from);
        self::assertSame('2026-08-31 23:59:59', $to);
    }

    #[Test]
    public function itParsesFebruaryOfALeapYearAsTwentyNineDays(): void
    {
        [$from, $to] = $this->periodParser->parse('2028-02');

        self::assertSame('2028-02-01 00:00:00', $from);
        self::assertSame('2028-02-29 23:59:59', $to);
    }

    #[Test]
    public function itRejectsAnUnrecognizedPeriodInsteadOfSilentlyFallingBackToThirtyDays(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->periodParser->parse('next_month');
    }

    #[Test]
    public function itRejectsAMalformedExplicitRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->periodParser->parse('2026-13-40:2026-13-41');
    }

    #[Test]
    public function itRejectsAnArbitraryUnsupportedWord(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->periodParser->parse('january');
    }
}

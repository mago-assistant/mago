<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use MagoAssistant\Mago\Service\Skills\PeriodParser;
use MagoAssistant\Mago\Service\Time\StoreTime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PeriodParserTest extends TestCase
{
    private PeriodParser $periodParser;

    protected function setUp(): void
    {
        $this->periodParser = $this->parserIn('UTC');
    }

    #[Test]
    public function aDayIsTheStoresDayConvertedToUtcForTheQuery(): void
    {
        $parser = $this->parserIn('Europe/Amsterdam');

        self::assertSame(['2026-10-06 22:00:00', '2026-10-07 21:59:59'], $parser->parse('2026-10-07'));
        self::assertSame(['2026-11-30 23:00:00', '2026-12-01 22:59:59'], $parser->parse('2026-12-01'));
        self::assertSame(['2026-10-07 00:00:00', '2026-10-07 23:59:59'], $parser->parseLocal('2026-10-07'));
    }

    #[Test]
    public function theDayDaylightSavingEndsHasTwentyFiveHours(): void
    {
        $parser = $this->parserIn('Europe/Amsterdam');

        self::assertSame(['2026-10-24 22:00:00', '2026-10-25 22:59:59'], $parser->parse('2026-10-25'));
        self::assertSame('2026-10-25 23:00:00', $parser->parse('2026-10-26')[0]);
    }

    #[Test]
    public function consecutiveDaysLeaveNoGapWhereDaylightSavingEndsAtMidnight(): void
    {
        $parser = $this->parserIn('America/Santiago');

        $end = new \DateTimeImmutable($parser->parse('2026-04-04')[1], new \DateTimeZone('UTC'));
        $nextStart = new \DateTimeImmutable($parser->parse('2026-04-05')[0], new \DateTimeZone('UTC'));

        self::assertSame(1, $nextStart->getTimestamp() - $end->getTimestamp());
    }

    #[Test]
    public function aMonthAndARangeConvertBothBounds(): void
    {
        $parser = $this->parserIn('Europe/Amsterdam');

        self::assertSame(['2026-09-30 22:00:00', '2026-10-31 22:59:59'], $parser->parse('2026-10'));
        self::assertSame(['2026-10-04 22:00:00', '2026-10-07 21:59:59'], $parser->parse('2026-10-05:2026-10-07'));
        self::assertSame('2026-09-30 22:00:00', $parser->getFromDate('2026-10'));
    }

    #[Test]
    public function todayIsTheStoresToday(): void
    {
        $zone = new \DateTimeZone('Pacific/Kiritimati');
        $today = (new \DateTimeImmutable('now', $zone))->format('Y-m-d');

        [$from, $to] = $this->parserIn('Pacific/Kiritimati')->parseLocal('today');

        self::assertSame($today . ' 00:00:00', $from);
        self::assertSame($today . ' 23:59:59', $to);
    }

    #[Test]
    public function allKeepsItsLowerBoundInAnyTimezone(): void
    {
        self::assertSame('1970-01-01 00:00:00', $this->parserIn('Europe/Amsterdam')->parse('all')[0]);
    }

    private function parserIn(string $timezone): PeriodParser
    {
        $config = $this->createStub(TimezoneInterface::class);
        $config->method('getConfigTimezone')->willReturn($timezone);

        return new PeriodParser(new StoreTime($config));
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
    public function itParsesASingleDay(): void
    {
        [$from, $to] = $this->periodParser->parse('2026-05-10');

        self::assertSame('2026-05-10 00:00:00', $from);
        self::assertSame('2026-05-10 23:59:59', $to);
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

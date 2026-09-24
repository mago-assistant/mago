<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills;

class PeriodParser
{
    /**
     * The lower bound of "all": far enough back to predate any store's first row, and a real date
     * so a query can keep using a plain >= comparison.
     */
    private const BEGINNING_OF_TIME = '1970-01-01 00:00:00';

    /**
     * Parse period string into [from, to] date strings
     *
     * @return string[] [from, to]
     */
    public function parse(string $period): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return match ($period) {
            'today' => [$now->format('Y-m-d 00:00:00'), $now->format('Y-m-d 23:59:59')],
            'yesterday' => [
                $now->modify('-1 day')->format('Y-m-d 00:00:00'),
                $now->modify('-1 day')->format('Y-m-d 23:59:59'),
            ],
            '7days' => [$now->modify('-7 days')->format('Y-m-d 00:00:00'), $now->format('Y-m-d 23:59:59')],
            '30days' => [$now->modify('-30 days')->format('Y-m-d 00:00:00'), $now->format('Y-m-d 23:59:59')],
            'this_month' => [$now->format('Y-m-01 00:00:00'), $now->format('Y-m-d 23:59:59')],
            'last_month' => [
                $now->modify('first day of last month')->format('Y-m-d 00:00:00'),
                $now->modify('last day of last month')->format('Y-m-d 23:59:59'),
            ],
            'this_year' => [$now->format('Y-01-01 00:00:00'), $now->format('Y-m-d 23:59:59')],
            'last_year' => [
                $now->modify('-1 year')->format('Y-01-01 00:00:00'),
                $now->modify('-1 year')->format('Y-12-31 23:59:59'),
            ],
            'all' => [self::BEGINNING_OF_TIME, $now->format('Y-m-d 23:59:59')],
            // Written out rather than keyed. A model asked about "last week" says so in words, and
            // refusing prose it could have understood costs a round trip to learn nothing.
            'last week', 'past week', 'last 7 days' => [
                $now->modify('-7 days')->format('Y-m-d 00:00:00'),
                $now->format('Y-m-d 23:59:59'),
            ],
            'last 30 days', 'past month' => [
                $now->modify('-30 days')->format('Y-m-d 00:00:00'),
                $now->format('Y-m-d 23:59:59'),
            ],
            default => $this->parseDateRange($period),
        };
    }

    /**
     * The "from" of a period, for a query that only needs a lower bound. It reads the same
     * vocabulary as parse(), and refuses the same strings: a period nobody recognises used to
     * become the last thirty days here, so "everything since 2020" quietly answered about a month.
     */
    public function getFromDate(string $period): string
    {
        return $this->parse($period)[0];
    }

    private function parseDateRange(string $period): array
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2}):(\d{4}-\d{2}-\d{2})$/', $period, $matches) === 1) {
            $this->assertValidDate($matches[1], $period);
            $this->assertValidDate($matches[2], $period);
            return [$matches[1] . ' 00:00:00', $matches[2] . ' 23:59:59'];
        }

        if (preg_match('/^(\d{4})$/', $period, $matches) === 1) {
            $yearStart = $this->assertValidDate($matches[1] . '-01-01', $period);

            return [
                $yearStart->format('Y-01-01 00:00:00'),
                $yearStart->format('Y-12-31 23:59:59'),
            ];
        }

        if (preg_match('/^(\d{4})-(\d{2})$/', $period, $matches) === 1) {
            $monthStart = $this->assertValidDate($matches[1] . '-' . $matches[2] . '-01', $period);
            return [
                $monthStart->format('Y-m-01 00:00:00'),
                $monthStart->modify('last day of this month')->format('Y-m-d 23:59:59'),
            ];
        }

        throw new \InvalidArgumentException(sprintf(
            'Unrecognized period "%s". Use "today", "yesterday", "7days", "30days", "this_month", "last_month", '
            . '"this_year", "last_year", "all" for no lower bound, "YYYY" for a whole year, "YYYY-MM" for a month, or '
            . '"YYYY-MM-DD:YYYY-MM-DD" for a custom range.',
            $period
        ));
    }

    private function assertValidDate(string $date, string $period): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException(sprintf('Invalid date "%s" in period "%s".', $date, $period));
        }

        return $parsed;
    }
}

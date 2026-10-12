<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Time;

/**
 * The years or months a stretch of stored timestamps covers, on the store's clock. YEAR() or
 * DATE_FORMAT() on a UTC column puts an order placed just after local midnight on the 1st in the
 * month before, while the period it was counted in is read on the store's clock (issue #255). Each
 * bucket comes with the UTC instant its local start falls on, so a query can bucket a stored
 * timestamp exactly without the database knowing any timezone, daylight saving included.
 */
class CalendarBuckets
{
    private const FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly StoreTime $storeTime
    ) {
    }

    /**
     * @param string $utcFirst "Y-m-d H:i:s" in UTC, the earliest timestamp to cover
     * @param string $utcLast "Y-m-d H:i:s" in UTC, the latest timestamp to cover
     * @return list<array{label: string, from: string}> oldest first; "from" is the UTC start
     */
    public function years(string $utcFirst, string $utcLast): array
    {
        return $this->buckets($utcFirst, $utcLast, 'Y', 'Y-01-01 00:00:00', '+1 year');
    }

    /**
     * @param string $utcFirst "Y-m-d H:i:s" in UTC, the earliest timestamp to cover
     * @param string $utcLast "Y-m-d H:i:s" in UTC, the latest timestamp to cover
     * @return list<array{label: string, from: string}> oldest first; "from" is the UTC start
     */
    public function months(string $utcFirst, string $utcLast): array
    {
        return $this->buckets($utcFirst, $utcLast, 'Y-m', 'Y-m-01 00:00:00', '+1 month');
    }

    /**
     * The calendar is walked as wall-clock dates without a timezone, so a step never lands an hour
     * off; only each start is converted, which is where daylight saving is accounted for.
     *
     * @return list<array{label: string, from: string}>
     */
    private function buckets(
        string $utcFirst,
        string $utcLast,
        string $labelFormat,
        string $startFormat,
        string $step
    ): array {
        $wallClock = new \DateTimeZone('UTC');
        $last = $this->storeTime->toLocal($utcLast);
        $start = new \DateTimeImmutable(
            $this->wallClock($this->storeTime->toLocal($utcFirst))->format($startFormat),
            $wallClock
        );

        $buckets = [];
        while ($start->format(self::FORMAT) <= $last) {
            $buckets[] = [
                'label' => $start->format($labelFormat),
                'from' => $this->storeTime->toUtc($start->format(self::FORMAT)),
            ];
            $start = $start->modify($step);
        }

        return $buckets;
    }

    private function wallClock(string $localDateTime): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat(self::FORMAT, $localDateTime, new \DateTimeZone('UTC'));
        if ($parsed === false) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a "Y-m-d H:i:s" timestamp.', $localDateTime));
        }

        return $parsed;
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Time;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Magento stores created_at and friends in UTC and shows them in the configured timezone. An admin
 * asking about "7 October" means 7 October on their clock, so a period is read in that timezone and
 * only its bounds are converted to UTC for the query, and a stored timestamp is converted back
 * before the model repeats it (issue #255).
 */
class StoreTime
{
    private const FORMAT = 'Y-m-d H:i:s';

    private ?\DateTimeZone $zone = null;

    public function __construct(
        private readonly TimezoneInterface $timezone
    ) {
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $this->zone());
    }

    /**
     * @param string $localDateTime "Y-m-d H:i:s" on the store's clock
     * @return string "Y-m-d H:i:s" in UTC
     */
    public function toUtc(string $localDateTime): string
    {
        return $this->convert($localDateTime, $this->zone(), new \DateTimeZone('UTC'));
    }

    /**
     * @param string $utcDateTime "Y-m-d H:i:s" as stored
     * @return string "Y-m-d H:i:s" on the store's clock, or the input unchanged when it is no such date
     */
    public function toLocal(string $utcDateTime): string
    {
        return $this->convert($utcDateTime, new \DateTimeZone('UTC'), $this->zone());
    }

    private function convert(string $dateTime, \DateTimeZone $from, \DateTimeZone $to): string
    {
        $parsed = \DateTimeImmutable::createFromFormat(self::FORMAT, $dateTime, $from);
        if ($parsed === false) {
            return $dateTime;
        }

        return $parsed->setTimezone($to)->format(self::FORMAT);
    }

    private function zone(): \DateTimeZone
    {
        if ($this->zone === null) {
            try {
                $this->zone = new \DateTimeZone($this->timezone->getConfigTimezone() ?: 'UTC');
            } catch (\Exception) {
                $this->zone = new \DateTimeZone('UTC');
            }
        }

        return $this->zone;
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Flag;

/**
 * Keeps a snapshot small enough to store in one row.
 *
 * Every provider call of a turn can carry a request payload of its own, and each of those holds the
 * conversation so far, so a long tool-heavy turn adds up past what mago_flag.snapshot (mediumtext)
 * or a default max_allowed_packet takes. Payloads are dropped oldest call first until the snapshot
 * fits, which keeps the call that produced the answer the longest, and the snapshot says it was
 * trimmed so nobody mistakes the gap for debug logging having been off.
 */
final class SnapshotSizeLimit
{
    /** Below MySQL's older 4MB max_allowed_packet default, with room for the rest of the INSERT */
    public const DEFAULT_MAX_BYTES = 3_500_000;

    public function __construct(private readonly int $maxBytes = self::DEFAULT_MAX_BYTES)
    {
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public function fit(array $snapshot): array
    {
        $calls = $snapshot['usage']['calls'] ?? [];

        foreach (array_keys($calls) as $index) {
            if ($this->fits($snapshot)) {
                return $snapshot;
            }

            $snapshot['usage']['calls'][$index]['request_payload'] = null;
            $snapshot['usage']['calls'][$index]['response_payload'] = null;
            $snapshot['usage']['payloads_trimmed'] = true;
        }

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function fits(array $snapshot): bool
    {
        return strlen((string)json_encode($snapshot)) <= $this->maxBytes;
    }
}

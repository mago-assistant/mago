<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Flag;

use MagoAssistant\Mago\Service\Flag\SnapshotSizeLimit;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SnapshotSizeLimitTest extends TestCase
{
    #[Test]
    public function itLeavesASnapshotThatFitsAlone(): void
    {
        $snapshot = $this->snapshotWithPayloads(2, 100);

        $fitted = (new SnapshotSizeLimit(10_000))->fit($snapshot);

        self::assertSame($snapshot, $fitted);
    }

    #[Test]
    public function itDropsPayloadsOldestCallFirstUntilTheSnapshotFits(): void
    {
        $snapshot = $this->snapshotWithPayloads(3, 1_000);

        $fitted = (new SnapshotSizeLimit(1_800))->fit($snapshot);

        $calls = $fitted['usage']['calls'];
        self::assertNull($calls[0]['request_payload']);
        self::assertNull($calls[1]['request_payload']);
        self::assertNotNull($calls[2]['request_payload'], 'the call that produced the answer is kept longest');
        self::assertTrue($fitted['usage']['payloads_trimmed']);
        self::assertLessThanOrEqual(1_800, strlen((string)json_encode($fitted)));
    }

    #[Test]
    public function itKeepsTheRestOfTheSnapshotWhenPayloadsAreDropped(): void
    {
        $snapshot = $this->snapshotWithPayloads(2, 1_000);

        $fitted = (new SnapshotSizeLimit(500))->fit($snapshot);

        self::assertSame('Fourteen.', $fitted['answer']['content']);
        self::assertSame(2, count($fitted['usage']['calls']));
        self::assertSame(1234, $fitted['usage']['input_tokens']);
    }

    #[Test]
    public function itLeavesASnapshotWithoutUsageAlone(): void
    {
        $snapshot = ['answer' => ['content' => str_repeat('x', 1_000)], 'usage' => null];

        $fitted = (new SnapshotSizeLimit(10))->fit($snapshot);

        self::assertSame($snapshot, $fitted);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotWithPayloads(int $calls, int $payloadLength): array
    {
        return [
            'answer' => ['content' => 'Fourteen.'],
            'usage' => [
                'input_tokens' => 1234,
                'calls' => array_map(
                    static fn (int $index): array => [
                        'request_payload' => ['call' => $index, 'body' => str_repeat('r', $payloadLength)],
                        'response_payload' => null,
                    ],
                    range(0, $calls - 1)
                ),
            ],
        ];
    }
}

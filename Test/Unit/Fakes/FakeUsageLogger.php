<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use MagoAssistant\Mago\Service\Usage\UsageLogger;

/**
 * Usage logger that records the turns it was told about instead of writing them to the database
 */
final class FakeUsageLogger extends UsageLogger
{
    /** @var list<array<string, mixed>> Logged rows, in order */
    public array $rows = [];

    public function __construct()
    {
    }

    public function log(
        int $adminUserId,
        ?int $conversationId,
        string $provider,
        string $model,
        int $inputTokens,
        int $outputTokens,
        array $skillNames = [],
        ?array $requestPayload = null,
        ?array $responsePayload = null,
        ?int $cacheReadTokens = null,
        ?int $cacheWriteTokens = null
    ): void {
        $this->rows[] = [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cache_read_tokens' => $cacheReadTokens,
            'cache_write_tokens' => $cacheWriteTokens,
        ];
    }

    public function getLoggedTurns(): int
    {
        return count($this->rows);
    }
}

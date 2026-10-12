<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Usage;

final class CacheShare
{
    public const REPORTED_INPUT_TOKENS_SUM = 'SUM(CASE WHEN cache_read_tokens IS NOT NULL THEN input_tokens END)';

    private const FULL_PERCENTAGE = 100;

    public function format(int|string|null $cacheReadTokens, int|string|null $inputTokens): string
    {
        if ($cacheReadTokens === null || $inputTokens === null || (int)$inputTokens <= 0) {
            return '';
        }

        return $this->percentage((int)$cacheReadTokens, (int)$inputTokens) . '%';
    }

    private function percentage(int $cacheReadTokens, int $inputTokens): int
    {
        return (int)round(min(self::FULL_PERCENTAGE, $cacheReadTokens / $inputTokens * self::FULL_PERCENTAGE));
    }
}

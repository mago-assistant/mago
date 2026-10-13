<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Usage;

/**
 * Estimates what token usage costs, per model, with prompt-cache reads priced at the cached-input rate.
 *
 * Prices are US dollars per million tokens and come from di.xml (`prices`, keyed by model id). The
 * model list the AI providers return holds no pricing, so a model without an entry is priced with
 * `fallbackPrice`, which has no cache discount: the estimate for such a model is the plain
 * input/output one.
 *
 * Input tokens include the tokens read from the cache, as CacheShare assumes; a cache-write count is
 * taken out of the plain input as well. A NULL cache count (the provider did not report it) is zero.
 */
class CostEstimator
{
    private const PER_TOKENS = 1000000;

    private const COST_DECIMALS = 4;

    /**
     * @param array<string, array{input: float|int|string, cached_input?: float|int|string, cache_write?: float|int|string, output: float|int|string}> $prices
     * @param array{input: float|int|string, cached_input?: float|int|string, cache_write?: float|int|string, output: float|int|string} $fallbackPrice
     */
    public function __construct(
        private readonly array $prices = [],
        private readonly array $fallbackPrice = ['input' => 3.0, 'output' => 15.0]
    ) {
    }

    public function estimate(
        string $model,
        int $inputTokens,
        int $outputTokens,
        int|string|null $cacheReadTokens = null,
        int|string|null $cacheWriteTokens = null
    ): float {
        return round(
            $this->rawCost($model, $inputTokens, $outputTokens, $cacheReadTokens, $cacheWriteTokens),
            self::COST_DECIMALS
        );
    }

    /**
     * Total for rows of token sums, one row per model (or per call): keys `model`, `input_tokens`,
     * `output_tokens`, `cache_read_tokens` and `cache_write_tokens`.
     *
     * @param iterable<array<string, mixed>> $rows
     */
    public function estimateRows(iterable $rows): float
    {
        $total = 0.0;
        foreach ($rows as $row) {
            $total += $this->rawCost(
                (string)($row['model'] ?? ''),
                (int)($row['input_tokens'] ?? 0),
                (int)($row['output_tokens'] ?? 0),
                $row['cache_read_tokens'] ?? null,
                $row['cache_write_tokens'] ?? null
            );
        }

        return round($total, self::COST_DECIMALS);
    }

    private function rawCost(
        string $model,
        int $inputTokens,
        int $outputTokens,
        int|string|null $cacheReadTokens,
        int|string|null $cacheWriteTokens
    ): float {
        $price = $this->prices[$model] ?? $this->fallbackPrice;
        $inputRate = (float)$price['input'];
        $read = min($inputTokens, max(0, (int)$cacheReadTokens));
        $write = min($inputTokens - $read, max(0, (int)$cacheWriteTokens));
        $plain = $inputTokens - $read - $write;

        return (
            $plain * $inputRate
            + $read * (float)($price['cached_input'] ?? $inputRate)
            + $write * (float)($price['cache_write'] ?? $inputRate)
            + $outputTokens * (float)$price['output']
        ) / self::PER_TOKENS;
    }
}

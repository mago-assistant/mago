<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills;

/**
 * The row count a list action fetches, from the model's "limit" argument.
 *
 * Magento reads a page size of 0 as "no limit", in a collection's setPageSize() and a select's
 * limit() alike, so a model that sends limit 0 (or is told to) would load a whole table: every row
 * through the privacy filter into the vault, for a result the response cap then cuts anyway. A
 * missing or non-numeric value falls back to the action's default; anything else lands between 1
 * and the maximum.
 */
class ResultLimit
{
    public const MAX = 50;

    /**
     * The most rows a download of a tool call holds (#298). The chat answer stays at MAX; the file
     * may be larger, but one link should not pull a whole catalog through the admin.
     */
    public const EXPORT_MAX = 10000;

    /**
     * How many rows an export reads per query
     */
    public const EXPORT_PAGE_SIZE = 1000;

    /**
     * @param array<array-key, mixed> $params
     */
    public static function fromParams(array $params, int $default, int $max = self::MAX): int
    {
        $requested = $params['limit'] ?? null;
        $limit = is_numeric($requested) ? (int)$requested : $default;

        return max(1, min($limit, $max));
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use MagoAssistant\Mago\Service\Skills\Inventory\DeadStockReport;

/**
 * Answers from fixed rows and records the arguments every call received.
 */
class FakeDeadStockReport extends DeadStockReport
{
    /** @var list<array{method:string,type:string,months:int,min_days_of_cover:int,limit?:int}> */
    public array $calls = [];

    /**
     * @param list<array<string,mixed>> $rows Rows sorted by stock value, highest first
     */
    public function __construct(private readonly array $rows = [])
    {
    }

    public function build(
        string $type,
        int $months,
        int $minDaysOfCover = self::DEFAULT_MIN_DAYS_OF_COVER,
        int $limit = 20
    ): array {
        $this->calls[] = [
            'method' => 'build',
            'type' => $type,
            'months' => $months,
            'min_days_of_cover' => $minDaysOfCover,
            'limit' => $limit,
        ];

        return array_slice($this->rows, 0, $limit);
    }

    public function summary(
        string $type,
        int $months,
        int $minDaysOfCover = self::DEFAULT_MIN_DAYS_OF_COVER
    ): array {
        $this->calls[] = [
            'method' => 'summary',
            'type' => $type,
            'months' => $months,
            'min_days_of_cover' => $minDaysOfCover,
        ];

        return [
            'product_count' => count($this->rows),
            'total_qty' => (float)array_sum(array_column($this->rows, 'qty')),
            'total_stock_value' => round((float)array_sum(array_column($this->rows, 'stock_value')), 2),
        ];
    }

    public function chunks(
        string $type,
        int $months,
        int $minDaysOfCover = self::DEFAULT_MIN_DAYS_OF_COVER,
        int $chunkSize = self::EXPORT_CHUNK_SIZE
    ): \Generator {
        $this->calls[] = [
            'method' => 'chunks',
            'type' => $type,
            'months' => $months,
            'min_days_of_cover' => $minDaysOfCover,
        ];

        yield from $this->rows === [] ? [] : array_chunk($this->rows, max(1, $chunkSize));
    }

    public function since(int $months, ?\DateTimeImmutable $today = null): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-03-24');
    }
}

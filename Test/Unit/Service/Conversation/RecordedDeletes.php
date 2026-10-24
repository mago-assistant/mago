<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Conversation;

/**
 * The deletes a connection was asked to run, in order, so a test asserts on what was deleted rather
 * than on how often a method was called.
 */
final class RecordedDeletes
{
    /** @var list<array{table: string, where: array<mixed>}> */
    private array $deletes = [];

    /**
     * @param array<mixed> $where
     */
    public function record(string $table, array $where): void
    {
        $this->deletes[] = ['table' => $table, 'where' => $where];
    }

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        return array_column($this->deletes, 'table');
    }

    /**
     * @return list<array<mixed>>
     */
    public function forTable(string $table): array
    {
        return array_values(array_map(
            static fn (array $delete): array => $delete['where'],
            array_filter($this->deletes, static fn (array $delete): bool => $delete['table'] === $table)
        ));
    }
}

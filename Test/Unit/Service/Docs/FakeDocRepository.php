<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Docs;

use MagoAssistant\Mago\Model\Doc\Repository;

/**
 * Holds the corpus in memory instead of the mago_doc table
 */
final class FakeDocRepository extends Repository
{
    /** @var array<int, array<string, mixed>> */
    private array $rows = [];

    public function __construct()
    {
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function withRows(array $rows): self
    {
        $this->rows = $rows;

        return $this;
    }

    public function count(): int
    {
        return count($this->rows);
    }

    public function replaceAll(array $rows): void
    {
        $this->rows = $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRows(): array
    {
        return $this->rows;
    }
}

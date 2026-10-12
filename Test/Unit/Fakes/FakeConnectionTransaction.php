<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use MagoAssistant\Mago\Service\Api\InProcess\ConnectionTransactionInterface;

/**
 * A connection that only counts how deeply transactions are nested, as Magento's MySQL adapter does.
 */
final class FakeConnectionTransaction implements ConnectionTransactionInterface
{
    private int $rollBacks = 0;

    public function __construct(
        private int $level = 0
    ) {
    }

    public function begin(): void
    {
        $this->level++;
    }

    public function commit(): void
    {
        $this->level--;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function rollBack(): void
    {
        if ($this->level === 0) {
            throw new \LogicException('Asymmetric transaction rollback.');
        }

        $this->level--;
        $this->rollBacks++;
    }

    public function rollBacks(): int
    {
        return $this->rollBacks;
    }
}

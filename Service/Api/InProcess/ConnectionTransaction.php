<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Api\InProcess;

use Magento\Framework\App\ResourceConnection;

class ConnectionTransaction implements ConnectionTransactionInterface
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function getLevel(): int
    {
        return (int)$this->resourceConnection->getConnection()->getTransactionLevel();
    }

    public function rollBack(): void
    {
        $this->resourceConnection->getConnection()->rollBack();
    }
}

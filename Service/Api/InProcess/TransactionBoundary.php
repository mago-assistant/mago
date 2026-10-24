<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Api\InProcess;

/**
 * Rolls back every transaction a failed call left open. Magento's resource models and EntityManager
 * only roll back on an \Exception, so a PHP \Error thrown by an observer or plugin during a save leaves
 * the transaction open. Over HTTP that request ended and MySQL rolled it back; in-process the chat
 * keeps using the connection, and every later commit in the request would be nested inside it and lost.
 */
class TransactionBoundary
{
    public function __construct(
        private readonly ConnectionTransactionInterface $connectionTransaction
    ) {
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function run(callable $operation): mixed
    {
        $level = $this->connectionTransaction->getLevel();

        try {
            return $operation();
        } catch (\Throwable $throwable) {
            $this->rollBackTo($level);
            throw $throwable;
        }
    }

    private function rollBackTo(int $level): void
    {
        $openedLevels = $this->connectionTransaction->getLevel() - $level;
        for ($rolledBack = 0; $rolledBack < $openedLevels; $rolledBack++) {
            $this->connectionTransaction->rollBack();
        }
    }
}

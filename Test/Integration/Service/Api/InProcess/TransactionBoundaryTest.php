<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Integration\Service\Api\InProcess;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use MagoAssistant\Mago\Service\Api\InProcess\ConnectionTransaction;
use MagoAssistant\Mago\Service\Api\InProcess\TransactionBoundary;
use MagoAssistant\Mago\Test\Integration\MagentoObjectManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * On Magento's real MySQL adapter: a PHP \Error inside nested transactions, the way a resource model
 * save fails when an observer crashes, must not leave the shared connection inside a transaction.
 */
final class TransactionBoundaryTest extends TestCase
{
    private AdapterInterface $connection;

    protected function setUp(): void
    {
        $this->connection = MagentoObjectManager::get()->get(ResourceConnection::class)->getConnection();
    }

    #[Test]
    public function itClosesTheTransactionsACrashedSaveLeftOpenOnTheConnection(): void
    {
        $boundary = new TransactionBoundary(new ConnectionTransaction(
            MagentoObjectManager::get()->get(ResourceConnection::class)
        ));
        $levelBefore = $this->connection->getTransactionLevel();
        $failure = null;

        try {
            $boundary->run(function (): never {
                $this->connection->beginTransaction();
                $this->connection->beginTransaction();
                throw new \Error('Call to a member function getId() on null');
            });
        } catch (\Error $error) {
            $failure = $error;
        }

        self::assertInstanceOf(\Error::class, $failure);
        self::assertSame($levelBefore, $this->connection->getTransactionLevel());
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Api\InProcess;

use MagoAssistant\Mago\Service\Api\InProcess\TransactionBoundary;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeConnectionTransaction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TransactionBoundaryTest extends TestCase
{
    #[Test]
    public function itRollsBackEveryTransactionAFailedOperationLeftOpen(): void
    {
        $transaction = new FakeConnectionTransaction();
        $boundary = new TransactionBoundary($transaction);

        $failure = $this->runFailing($boundary, static function () use ($transaction): never {
            $transaction->begin();
            $transaction->begin();
            throw new \Error('Call to a member function getId() on null');
        });

        self::assertSame('Call to a member function getId() on null', $failure->getMessage());
        self::assertSame(0, $transaction->getLevel());
        self::assertSame(2, $transaction->rollBacks());
    }

    #[Test]
    public function itKeepsTheTransactionsThatWereOpenBeforeTheOperation(): void
    {
        $transaction = new FakeConnectionTransaction(1);
        $boundary = new TransactionBoundary($transaction);

        $this->runFailing($boundary, static function () use ($transaction): never {
            $transaction->begin();
            throw new \RuntimeException('Could not save');
        });

        self::assertSame(1, $transaction->getLevel());
    }

    #[Test]
    public function itRollsNothingBackWhenTheFailedOperationClosedItsOwnTransaction(): void
    {
        $transaction = new FakeConnectionTransaction();
        $boundary = new TransactionBoundary($transaction);

        $this->runFailing($boundary, static function () use ($transaction): never {
            $transaction->begin();
            $transaction->rollBack();
            throw new \RuntimeException('Could not save');
        });

        self::assertSame(0, $transaction->getLevel());
        self::assertSame(1, $transaction->rollBacks());
    }

    #[Test]
    public function itReturnsWhatASuccessfulOperationReturns(): void
    {
        $transaction = new FakeConnectionTransaction();

        $result = (new TransactionBoundary($transaction))->run(static fn (): string => 'saved');

        self::assertSame('saved', $result);
        self::assertSame(0, $transaction->rollBacks());
    }

    private function runFailing(TransactionBoundary $boundary, callable $operation): \Throwable
    {
        try {
            $boundary->run($operation);
        } catch (\Throwable $throwable) {
            return $throwable;
        }

        self::fail('The operation was expected to fail');
    }
}

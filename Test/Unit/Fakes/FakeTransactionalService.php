<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

/**
 * A service whose save opens a transaction, the way a resource model does, and then either returns or
 * fails with the throwable it was given before it could commit.
 */
final class FakeTransactionalService
{
    public function __construct(
        private readonly FakeConnectionTransaction $transaction,
        private readonly ?\Throwable $failure = null
    ) {
    }

    public function save(string $name): string
    {
        $this->transaction->begin();
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->transaction->commit();

        return $name;
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Docs;

use Magento\Framework\HTTP\AsyncClient\HttpResponseDeferredInterface;
use Magento\Framework\HTTP\AsyncClient\Response;

/**
 * A request that has already finished, with either a response or the error it failed with
 */
final class FakeHttpResponseDeferred implements HttpResponseDeferredInterface
{
    private bool $isCancelled = false;

    private function __construct(private readonly Response|\Throwable $outcome)
    {
    }

    public static function resolved(Response $response): self
    {
        return new self($response);
    }

    public static function failed(\Throwable $error): self
    {
        return new self($error);
    }

    public function get(): Response
    {
        if ($this->outcome instanceof \Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }

    public function isDone(): bool
    {
        return true;
    }

    public function cancel(bool $force = false): void
    {
        $this->isCancelled = true;
    }

    public function isCancelled(): bool
    {
        return $this->isCancelled;
    }
}

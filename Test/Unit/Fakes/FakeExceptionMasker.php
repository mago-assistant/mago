<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Framework\Exception\AuthenticationException;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Framework\Webapi\Exception as WebapiException;
use MagoAssistant\Mago\Service\Api\InProcess\ExceptionMaskerInterface;

/**
 * Masks the way Magento\Framework\Webapi\ErrorProcessor does in production mode, and records what it
 * was handed.
 */
final class FakeExceptionMasker implements ExceptionMaskerInterface
{
    public const INTERNAL_ERROR = 'Internal Error. Details are available in Magento log file. Report ID: webapi-fake';

    /** @var list<\Exception> */
    private array $masked = [];

    /**
     * @return list<\Exception>
     */
    public function masked(): array
    {
        return $this->masked;
    }

    public function mask(\Exception $exception): WebapiException
    {
        $this->masked[] = $exception;

        return match (true) {
            $exception instanceof WebapiException => $exception,
            $exception instanceof LocalizedException => new WebapiException(
                new Phrase($exception->getRawMessage()),
                $exception->getCode(),
                $this->getHttpCode($exception),
                $exception->getParameters()
            ),
            default => new WebapiException(new Phrase(self::INTERNAL_ERROR), 0, WebapiException::HTTP_INTERNAL_ERROR),
        };
    }

    private function getHttpCode(LocalizedException $exception): int
    {
        return match (true) {
            $exception instanceof NoSuchEntityException => WebapiException::HTTP_NOT_FOUND,
            $exception instanceof AuthorizationException,
            $exception instanceof AuthenticationException => WebapiException::HTTP_UNAUTHORIZED,
            default => WebapiException::HTTP_BAD_REQUEST,
        };
    }
}

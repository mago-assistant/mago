<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Api\InProcess;

use Magento\Framework\Webapi\ErrorProcessor;
use Magento\Framework\Webapi\Exception as WebapiException;

class ExceptionMasker implements ExceptionMaskerInterface
{
    public function __construct(
        private readonly ErrorProcessor $errorProcessor
    ) {
    }

    public function mask(\Exception $exception): WebapiException
    {
        return $this->errorProcessor->maskException($exception);
    }
}

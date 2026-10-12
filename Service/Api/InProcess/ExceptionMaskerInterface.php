<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Api\InProcess;

use Magento\Framework\Webapi\Exception as WebapiException;

interface ExceptionMaskerInterface
{
    /**
     * Turn any exception into the web API exception REST would have answered with
     */
    public function mask(\Exception $exception): WebapiException;
}

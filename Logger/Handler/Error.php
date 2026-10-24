<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Logger\Handler;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

class Error extends Base
{
    /**
     * Warnings too: ErrorLogger::addWarning() reports problems a developer must see without debug
     * mode, such as an add-on action reading tool arguments it never declared
     */
    protected $loggerType = Logger::WARNING;
    protected $fileName = '/var/log/mago-error.log';
}

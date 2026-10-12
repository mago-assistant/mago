<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Logger\Handler;

use Magento\Framework\App\Filesystem\DirectoryList;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;

/**
 * Daily rotated debug log (var/log/mago-debug-YYYY-MM-DD.log), so a debug mode left on cannot
 * grow one file without bound. Files older than MAX_FILES days are removed.
 */
class Debug extends RotatingFileHandler
{
    public const FILE_NAME = 'mago-debug.log';
    public const MAX_FILES = 7;

    public function __construct(DirectoryList $directoryList)
    {
        parent::__construct(
            $directoryList->getPath(DirectoryList::LOG) . '/' . self::FILE_NAME,
            self::MAX_FILES,
            Logger::DEBUG
        );
    }
}

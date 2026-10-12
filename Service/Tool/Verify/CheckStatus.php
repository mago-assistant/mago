<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Tool\Verify;

enum CheckStatus: string
{
    case Pass = 'ok';
    case Warning = 'warn';
    case Failure = 'fail';
}

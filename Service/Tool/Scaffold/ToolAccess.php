<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Tool\Scaffold;

enum ToolAccess: string
{
    case Read = 'read';
    case Write = 'write';
}

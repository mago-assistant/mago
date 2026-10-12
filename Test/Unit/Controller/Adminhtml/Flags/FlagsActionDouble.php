<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Controller\Adminhtml\Flags;

use MagoAssistant\Mago\Controller\Adminhtml\Flags\ReadsConversations;

final class FlagsActionDouble extends ActionDouble
{
    use ReadsConversations;
}

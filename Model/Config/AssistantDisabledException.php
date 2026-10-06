<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Config;

use Magento\Framework\Exception\LocalizedException;

/**
 * The assistant is switched off in the configuration. The message is a setup instruction, safe to
 * show the admin or API caller as written.
 */
class AssistantDisabledException extends LocalizedException
{
    public function __construct()
    {
        parent::__construct(
            __('The assistant is currently disabled. Enable it in Stores > Configuration > Mago Assistant.')
        );
    }
}

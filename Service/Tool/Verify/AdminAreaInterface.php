<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Tool\Verify;

/**
 * Puts a CLI run in the adminhtml area, where the chat runs its tools (backend URLs, admin ACL).
 */
interface AdminAreaInterface
{
    public function enter(): void;
}

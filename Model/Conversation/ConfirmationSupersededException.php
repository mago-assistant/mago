<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Conversation;

/**
 * The proposal was retired because the admin sent a new message past it (#332). "Already been
 * handled. Nothing was run again." would read as if it ran once; it never ran at all.
 */
class ConfirmationSupersededException extends ConfirmationUnavailableException
{
    public function __construct()
    {
        parent::__construct('A newer message replaced this proposal. Nothing was run. Ask again if it is still wanted.');
    }
}

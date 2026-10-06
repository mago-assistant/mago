<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Conversation;

/**
 * Another request (a second click, the panel and the REST API at once) claimed the confirmation
 * first, so its writes run there and must not run again here.
 */
class ConfirmationAlreadyHandledException extends ConfirmationUnavailableException
{
    public function __construct()
    {
        parent::__construct('This action has already been handled. Nothing was run again.');
    }
}

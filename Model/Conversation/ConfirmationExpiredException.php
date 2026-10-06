<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Conversation;

/**
 * The write was proposed too long ago to be approved: the store may have moved on since the admin
 * read the card, so a fresh proposal is needed.
 */
class ConfirmationExpiredException extends ConfirmationUnavailableException
{
    public function __construct()
    {
        parent::__construct(
            'This action was proposed more than an hour ago and can no longer be confirmed. '
            . 'Nothing was run. Ask again to get a fresh proposal.'
        );
    }
}

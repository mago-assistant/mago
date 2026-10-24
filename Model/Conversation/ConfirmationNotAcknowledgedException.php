<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Conversation;

/**
 * The write cannot be undone or changes something to weigh first, and the confirm request did not
 * carry the acknowledgement the card asks for. Nothing ran and the proposal stays pending. The
 * panel has already turned that card into a failed run, so the message sends the admin to a
 * reload, which rebuilds the card with its checkbox from the stored proposal.
 */
class ConfirmationNotAcknowledgedException extends ConfirmationUnavailableException
{
    public function __construct()
    {
        parent::__construct(
            'This action only runs once you confirm that you understand what it changes. Nothing was run. '
            . 'Reload the conversation and tick the box on its card, '
            . 'or over the REST API send "acknowledged": true.'
        );
    }
}

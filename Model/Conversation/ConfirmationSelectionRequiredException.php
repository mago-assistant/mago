<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Conversation;

/**
 * A confirm without ticked tool call ids used to mean "run them all", so a crafted or buggy
 * request could run every action of a tick list nobody ticked (#332). A proposal that holds more
 * than one call now requires the selection to be spelled out.
 */
class ConfirmationSelectionRequiredException extends ConfirmationUnavailableException
{
    public function __construct()
    {
        parent::__construct(
            'This proposal holds several actions. Send tool_call_ids with the actions to run; nothing was run.'
        );
    }
}

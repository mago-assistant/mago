<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Conversation;

/**
 * The confirm named a selection that runs nothing: an empty tool_call_ids, or ids the proposal
 * does not hold. Claiming it anyway consumed the proposal, skipped every action and reported
 * success (#332 review), so a typo or an empty default list burnt the card for good. Refused
 * before the claim instead, so the proposal stays pending for a retry that picks.
 */
class ConfirmationSelectionMismatchException extends ConfirmationUnavailableException
{
    public function __construct()
    {
        parent::__construct(
            'tool_call_ids must name actions of this proposal. Nothing was run; the proposal is still pending.'
        );
    }
}

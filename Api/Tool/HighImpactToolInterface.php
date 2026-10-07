<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Api\Tool;

/**
 * Tool whose call, though it can be reverted, changes something the admin should weigh before
 * allowing it: how the store is secured, reached or mailed from, or what runs on every page.
 *
 * ChatService asks this before it sends the confirmation to the panel. A call with cautions is
 * shown with them and an acknowledgement checkbox rather than a plain Allow button, so a write
 * proposed from text the assistant read (a prompt injection) does not pass on one unread click.
 * @api
 */
interface HighImpactToolInterface extends ToolInterface
{
    /**
     * What the given invocation changes, one line each, for the confirmation card
     *
     * Empty when the call needs no more than the plain card.
     *
     * @param array $input The tool call input parameters
     * @param int $adminUserId Current admin user ID
     * @return string[]
     */
    public function getCautions(array $input, int $adminUserId): array;
}

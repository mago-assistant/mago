<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Api\Tool;

/**
 * Tool that decides how it is shown in the chat panel.
 *
 * Without it the panel derives the card title from the tool name ("my_tool" reads "My tool") and
 * the status line while it runs is "Running my_tool...". Built-in tools get their lines from a
 * list in ChatService; a tool from another module implements this instead.
 *
 * @api
 */
interface PresentableToolInterface extends ToolInterface
{
    /**
     * Title of the tool on cards and in the session log, e.g. "Stock alerts"
     *
     * @return string
     */
    public function getDisplayName(): string;

    /**
     * Status line while the call runs, e.g. "Checking stock alerts...", or null for the default
     *
     * The input is the call as the model proposed it, so personal values in it are still masked.
     *
     * @param string $action The invoked action, empty for a tool without actions
     * @param array $input The tool call input parameters
     * @return string|null
     */
    public function getStatusMessage(string $action, array $input): ?string;
}

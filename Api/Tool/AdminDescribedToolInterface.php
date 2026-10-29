<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Api\Tool;

/**
 * Tool that describes itself to the administrator on the Skills & Permissions page.
 *
 * getDescription() is written for the model: action slugs, parameter names and directives. Without
 * this interface the page shows the first sentence of that text instead.
 *
 * @api
 */
interface AdminDescribedToolInterface extends ToolInterface
{
    /**
     * One short sentence on what the tool does, e.g. "Look up and manage orders."
     *
     * Return an empty string to fall back to the first sentence of getDescription().
     *
     * @return string
     */
    public function getAdminDescription(): string;
}

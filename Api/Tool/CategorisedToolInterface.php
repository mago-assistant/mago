<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Api\Tool;

/**
 * Tool that names the category it is listed under on the Skills & Permissions page itself.
 *
 * Without it the category is read from the tool's namespace, which says nothing for a tool that is
 * not a class of its own, such as one built from a manifest.
 *
 * @api
 */
interface CategorisedToolInterface extends ToolInterface
{
    /**
     * The category, as shown in the grid and its filter
     *
     * @return string
     */
    public function getCategory(): string;
}

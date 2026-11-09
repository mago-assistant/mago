<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Api\Tool;

/**
 * A source of tools the registry asks at construction, next to the tools di.xml lists one by one.
 * The manifest loader is one: every module's etc/mago_tools.xml becomes tools through it.
 *
 * @api
 */
interface ToolProviderInterface
{
    /**
     * @return ToolInterface[]
     */
    public function getTools(): array;
}

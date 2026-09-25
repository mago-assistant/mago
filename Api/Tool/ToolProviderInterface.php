<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Api\Tool;

/**
 * Supplies tools that are only known at runtime (e.g. discovered on a remote MCP server), next to
 * the tools wired statically into the ToolRegistry.
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

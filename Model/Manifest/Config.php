<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Manifest;

use Magento\Framework\Config\Data;

/**
 * The tool manifests of every module, each read on its own, cached with the configuration under CACHE_ID (di.xml).
 *
 * @phpstan-import-type ToolDef from \MagoAssistant\Mago\Model\Manifest\Config\Converter
 */
class Config extends Data
{
    /**
     * Carries the shape the converter writes: bump the suffix when that shape changes, so a tool
     * never reads a definition an older converter cached.
     */
    public const CACHE_ID = 'mago_tool_manifests';

    /**
     * @return array<string, ToolDef> Tool definitions keyed by tool name
     */
    public function getTools(): array
    {
        /** @var array<string, ToolDef> $tools */
        $tools = $this->get();

        return $tools;
    }
}

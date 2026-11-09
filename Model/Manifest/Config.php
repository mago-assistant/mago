<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Manifest;

use Magento\Framework\Config\Data;

/**
 * The merged tool manifests of every module, cached with the configuration under CACHE_ID (di.xml).
 *
 * @phpstan-import-type ToolDef from \MagoAssistant\Mago\Model\Manifest\Config\Converter
 */
class Config extends Data
{
    public const CACHE_ID = 'mago_tools_config';

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

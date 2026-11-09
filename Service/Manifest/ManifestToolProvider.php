<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Manifest;

use MagoAssistant\Mago\Api\Tool\ToolProviderInterface;
use MagoAssistant\Mago\Model\Manifest\Config;

/**
 * One ManifestTool per tool declared in any module's etc/mago_tools.xml.
 */
class ManifestToolProvider implements ToolProviderInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly ManifestToolFactory $toolFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTools(): array
    {
        $tools = [];
        foreach ($this->config->getTools() as $definition) {
            $tools[] = $this->toolFactory->create($definition);
        }

        return $tools;
    }
}

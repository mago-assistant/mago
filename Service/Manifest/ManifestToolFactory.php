<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Manifest;

use Magento\Framework\ObjectManagerInterface;

/**
 * One ManifestTool per manifest definition. Written out rather than generated so the provider can
 * be tested without the ObjectManager's code generation.
 *
 * @phpstan-import-type ToolDef from \MagoAssistant\Mago\Model\Manifest\Config\Converter
 */
class ManifestToolFactory
{
    public function __construct(
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    /**
     * @param ToolDef $definition
     */
    public function create(array $definition): ManifestTool
    {
        return $this->objectManager->create(ManifestTool::class, ['definition' => $definition]);
    }
}

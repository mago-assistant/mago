<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Manifest;

use MagoAssistant\Mago\Api\Tool\ToolProviderInterface;
use MagoAssistant\Mago\Model\Manifest\Config;
use MagoAssistant\Mago\Service\Error\ErrorReporter;

/**
 * One ManifestTool per tool declared in any module's etc/mago_tools.xml.
 *
 * The manifests are read when the registry is built, on every chat request and admin page that
 * lists the tools. A manifest another module ships can be malformed, and setup:upgrade and
 * di:compile never read it, so the error would first show at runtime: it is logged under
 * var/log/mago-error.log and the manifest tools are left out, while the built-in tools keep working.
 */
class ManifestToolProvider implements ToolProviderInterface
{
    private const CONTEXT = 'ManifestTools';

    public function __construct(
        private readonly Config $config,
        private readonly ManifestToolFactory $toolFactory,
        private readonly ErrorReporter $errorReporter
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTools(): array
    {
        try {
            $definitions = $this->config->getTools();
        } catch (\Throwable $exception) {
            $this->errorReporter->log(self::CONTEXT, $exception);

            return [];
        }

        $tools = [];
        foreach ($definitions as $definition) {
            try {
                $tools[] = $this->toolFactory->create($definition);
            } catch (\Throwable $exception) {
                $this->errorReporter->log(self::CONTEXT, $exception);
            }
        }

        return $tools;
    }
}

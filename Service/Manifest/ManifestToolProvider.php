<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Manifest;

use MagoAssistant\Mago\Api\Tool\ToolProviderInterface;
use MagoAssistant\Mago\Model\Manifest\Config;
use MagoAssistant\Mago\Model\Manifest\Config\Reader;
use MagoAssistant\Mago\Service\Error\ErrorReporter;

/**
 * One ManifestTool per tool declared in any module's etc/mago_tools.xml.
 *
 * The manifests are read when the registry is built, on every chat request and admin page that
 * lists the tools. The reader already leaves out a manifest that does not validate; what can still
 * fail here (a cache entry that cannot be read, a tool that cannot be built) is logged under
 * var/log/mago-error.log and left out as well, so the built-in tools keep working.
 */
class ManifestToolProvider implements ToolProviderInterface
{
    private const CONTEXT = Reader::LOG_CONTEXT;

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
            $this->report($exception);

            return [];
        }

        $tools = [];
        foreach ($definitions as $definition) {
            try {
                $tools[] = $this->toolFactory->create($definition);
            } catch (\Throwable $exception) {
                $this->report($exception);
            }
        }

        return $tools;
    }

    /**
     * Logging must not be what takes the registry down: the log directory may not be writable.
     */
    private function report(\Throwable $exception): void
    {
        try {
            $this->errorReporter->log(self::CONTEXT, $exception);
        } catch (\Throwable) {
            return;
        }
    }
}

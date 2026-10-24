<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Logger;

use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Api\Config\RepositoryInterface as ConfigRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Writes to var/log/mago-debug-<date>.log, but only while "Debug Mode" is on: every call is a no-op
 * otherwise, so call sites never have to gate themselves. The flag is read once per request.
 * Callers log metadata (ids, sizes, statuses), never names or vault tokens; the one message text
 * logged, the chat request and slash command, goes through PrivacyService::maskText first.
 */
class DebugLogger
{
    private ?bool $isDebugEnabled = null;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Json $json,
        private readonly ConfigRepositoryInterface $configRepository
    ) {
    }

    /**
     * @param string $type
     * @param mixed $data
     * @return void
     */
    public function addLog(string $type, $data): void
    {
        if (!$this->isDebugEnabled()) {
            return;
        }

        $message = $type . ': ';

        if (is_array($data) || is_object($data)) {
            $message .= $this->json->serialize(is_object($data) ? (array)$data : $data);
        } else {
            $message .= (string)$data;
        }

        $this->logger->info($message);
    }

    private function isDebugEnabled(): bool
    {
        return $this->isDebugEnabled ??= $this->configRepository->isDebugEnabled();
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Logger;

use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

class ErrorLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Json $json
    ) {
    }

    /**
     * @param string $type
     * @param mixed $data
     * @return void
     */
    public function addLog(string $type, $data): void
    {
        $message = $type . ': ';

        if (is_array($data) || is_object($data)) {
            $message .= $this->json->serialize(is_object($data) ? (array)$data : $data);
        } else {
            $message .= (string)$data;
        }

        $this->logger->error($message);
    }

    /**
     * Something worth a developer's look that did not fail the request
     *
     * @param array<string, mixed> $data
     */
    public function addWarning(string $type, array $data): void
    {
        $this->logger->warning($type . ': ' . $this->json->serialize($data));
    }
}

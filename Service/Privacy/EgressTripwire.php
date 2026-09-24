<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Privacy;

use Magento\Framework\App\State;
use MagoAssistant\Mago\Logger\ErrorLogger;

/**
 * Last-line egress check (issue #97 decision 6): just before a payload leaves for the provider it is
 * scanned once more, independently of the scrub and filter paths, for recognisable PII. Developer
 * mode logs a hit; throwing is reserved for the test flag so a filter regression fails a test run
 * loudly without ever breaking a live shop. Production skips the scan entirely.
 */
class EgressTripwire
{
    public function __construct(
        private readonly PiiHeuristic $heuristic,
        private readonly State $appState,
        private readonly ErrorLogger $errorLogger,
        private readonly bool $throwOnHit = false
    ) {
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     */
    public function inspect(array $messages): void
    {
        if (!$this->throwOnHit && !$this->inDeveloperMode()) {
            return;
        }

        foreach ($messages as $index => $message) {
            $found = $this->heuristic->detect((string)json_encode($message));
            if ($found === []) {
                continue;
            }

            if ($this->throwOnHit) {
                throw new \RuntimeException(
                    'Privacy tripwire: ' . implode(', ', $found) . ' detected in outbound message ' . $index
                );
            }

            // The classes and position are logged, never the values themselves.
            $this->errorLogger->addLog('Privacy Tripwire', [
                'message_index' => $index,
                'role' => (string)($message['role'] ?? ''),
                'classes' => $found,
            ]);
        }
    }

    private function inDeveloperMode(): bool
    {
        try {
            return $this->appState->getMode() === State::MODE_DEVELOPER;
        } catch (\Throwable) {
            return false;
        }
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Tool\Verify;

/**
 * One line of a mago:tool:verify report.
 */
final readonly class ToolCheck
{
    private function __construct(
        public CheckStatus $status,
        public string $message
    ) {
    }

    public static function pass(string $message): self
    {
        return new self(CheckStatus::Pass, $message);
    }

    public static function warning(string $message): self
    {
        return new self(CheckStatus::Warning, $message);
    }

    public static function failure(string $message): self
    {
        return new self(CheckStatus::Failure, $message);
    }

    public function isFailure(): bool
    {
        return $this->status === CheckStatus::Failure;
    }
}

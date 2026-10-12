<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Tool\Verify;

/**
 * What one execute() returned, what the model gets to see of it, and the checks on the difference.
 */
final readonly class ToolRun
{
    /**
     * @param array<array-key,mixed> $rawResult
     * @param array<array-key,mixed> $modelView
     * @param ToolCheck[] $checks
     */
    public function __construct(
        public array $rawResult,
        public array $modelView,
        public array $checks
    ) {
    }
}

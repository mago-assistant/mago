<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Api\InProcess;

final readonly class ResolvedRoute
{
    /**
     * @param string[] $aclResources
     * @param array<string, mixed> $inputData
     */
    public function __construct(
        public string $serviceClass,
        public string $serviceMethod,
        public string $routePath,
        public array $aclResources,
        public array $inputData,
        public ?int $inputArraySizeLimit = null
    ) {
    }

    public function isServiceMethod(string $serviceClass, string $serviceMethod): bool
    {
        return is_a(ltrim($this->serviceClass, '\\'), ltrim($serviceClass, '\\'), true)
            && $this->serviceMethod === $serviceMethod;
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Config values by path, the same for every scope unless withScopeValue() overrides one for a scope
 * type. Scope codes are not told apart: every store view of that type gets the override.
 */
final class FakeScopeConfig implements ScopeConfigInterface
{
    /**
     * @param array<string, string> $values
     * @param array<string, array<string, string>> $scopeValues Scope type => path => value
     */
    public function __construct(
        private readonly array $values = [],
        private readonly array $scopeValues = []
    ) {
    }

    /**
     * A value that overrides the plain ones in one scope type only, the way a website or store
     * view setting overrides the default
     */
    public function withScopeValue(string $scopeType, string $path, string $value): self
    {
        return new self($this->values, array_replace_recursive($this->scopeValues, [$scopeType => [$path => $value]]));
    }

    public function getValue($path, $scopeType = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeCode = null): ?string
    {
        return $this->scopeValues[$scopeType][$path] ?? $this->values[$path] ?? null;
    }

    public function isSetFlag($path, $scopeType = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeCode = null): bool
    {
        return (bool)$this->getValue($path, $scopeType, $scopeCode);
    }
}

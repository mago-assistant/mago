<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Config values by path, the same for every scope.
 */
final class FakeScopeConfig implements ScopeConfigInterface
{
    /**
     * @param array<string, string> $values
     */
    public function __construct(private readonly array $values = [])
    {
    }

    public function getValue($path, $scopeType = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeCode = null): ?string
    {
        return $this->values[$path] ?? null;
    }

    public function isSetFlag($path, $scopeType = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeCode = null): bool
    {
        return (bool)$this->getValue($path, $scopeType, $scopeCode);
    }
}

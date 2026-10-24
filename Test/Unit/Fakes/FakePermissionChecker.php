<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use MagoAssistant\Mago\Service\Skills\PermissionChecker;

final class FakePermissionChecker extends PermissionChecker
{
    /** @var array<string, bool> */
    private array $decisions = [];

    private array $explicitGrants = [];

    public function __construct()
    {
    }

    public function withDecision(string $skillName, string $action, bool $isAllowed): self
    {
        $this->decisions[$skillName . ':' . $action] = $isAllowed;

        return $this;
    }

    public function isAllowed(int $adminUserId, string $skillName, string $action = 'read'): bool
    {
        return $this->decisions[$skillName . ':' . $action] ?? false;
    }

    /**
     * An explicit per-user row, as the real checker reads it from mago_skill_permission; kept apart
     * from withDecision() because isAllowed() also answers yes through the module-wide fallback.
     */
    public function withExplicitGrant(string $skillName, string $action): self
    {
        $this->explicitGrants[$skillName . ':' . $action] = true;

        return $this;
    }

    public function isExplicitlyAllowed(int $adminUserId, string $skillName, string $action = 'read'): bool
    {
        return $this->explicitGrants[$skillName . ':' . $action] ?? false;
    }
}

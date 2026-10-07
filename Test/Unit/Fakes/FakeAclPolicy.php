<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Framework\Authorization\PolicyInterface;

/**
 * An ACL with the resources each role id allows.
 */
final class FakeAclPolicy implements PolicyInterface
{
    /**
     * @param array<int|string, string[]> $resourcesByRoleId
     */
    public function __construct(
        private readonly array $resourcesByRoleId
    ) {
    }

    public function isAllowed($roleId, $resourceId, $privilege = null): bool
    {
        return $roleId !== null && in_array($resourceId, $this->resourcesByRoleId[$roleId] ?? [], true);
    }
}

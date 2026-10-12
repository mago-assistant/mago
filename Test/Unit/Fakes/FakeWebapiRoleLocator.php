<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Webapi\Model\WebapiRoleLocator;

/**
 * Finds the role of the user in its context in a fixed map, and counts how often it was asked, each
 * time being a role query in Magento's own locator.
 */
final class FakeWebapiRoleLocator extends WebapiRoleLocator
{
    private int $lookups = 0;

    /**
     * @param array<int, string> $roleIdsByUserId
     */
    public function __construct(
        private readonly UserContextInterface $context,
        private readonly array $roleIdsByUserId
    ) {
    }

    public function getAclRoleId(): ?string
    {
        $this->lookups++;

        return $this->roleIdsByUserId[(int)$this->context->getUserId()] ?? null;
    }

    public function lookups(): int
    {
        return $this->lookups;
    }
}

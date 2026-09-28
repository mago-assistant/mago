<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Framework\AuthorizationInterface;

class FakeAclAuthorization implements AuthorizationInterface
{
    /**
     * @param string[] $allowedResources
     */
    public function __construct(
        private readonly array $allowedResources
    ) {
    }

    public function isAllowed($resource, $privilege = null): bool
    {
        return in_array($resource, $this->allowedResources, true);
    }
}

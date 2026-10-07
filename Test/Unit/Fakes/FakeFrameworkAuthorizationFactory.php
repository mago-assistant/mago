<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Framework\Authorization;
use Magento\Framework\AuthorizationFactory;

/**
 * Builds Magento's real Authorization from the policy and role locator it is handed.
 */
final class FakeFrameworkAuthorizationFactory extends AuthorizationFactory
{
    public function __construct()
    {
    }

    /**
     * @param array{aclPolicy: \Magento\Framework\Authorization\PolicyInterface, roleLocator: \Magento\Framework\Authorization\RoleLocatorInterface} $data
     */
    public function create(array $data = []): Authorization
    {
        return new Authorization($data['aclPolicy'], $data['roleLocator']);
    }
}

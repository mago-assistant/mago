<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Api\InProcess;

use Magento\Framework\Authorization\RoleLocatorInterface;

/**
 * An ACL role looked up once. Magento's WebapiRoleLocator queries the role on every isAllowed().
 */
final readonly class FixedAclRoleLocator implements RoleLocatorInterface
{
    public function __construct(
        private ?string $aclRoleId
    ) {
    }

    public function getAclRoleId(): ?string
    {
        return $this->aclRoleId;
    }
}

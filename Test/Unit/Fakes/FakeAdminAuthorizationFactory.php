<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Framework\AuthorizationInterface;
use MagoAssistant\Mago\Service\Api\InProcess\AdminAuthorizationFactory;

/**
 * Every admin user gets the same ACL resources; the ids asked for are recorded.
 */
final class FakeAdminAuthorizationFactory extends AdminAuthorizationFactory
{
    /** @var list<int> */
    private array $adminUserIds = [];

    /**
     * @param string[] $allowedResources
     */
    public function __construct(
        private readonly array $allowedResources
    ) {
    }

    public function create(int $adminUserId): AuthorizationInterface
    {
        $this->adminUserIds[] = $adminUserId;

        return new FakeAclAuthorization($this->allowedResources);
    }

    /**
     * @return list<int>
     */
    public function adminUserIds(): array
    {
        return $this->adminUserIds;
    }
}

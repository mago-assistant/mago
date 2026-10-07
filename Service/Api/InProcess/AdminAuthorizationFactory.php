<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Api\InProcess;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Authorization\PolicyInterface;
use Magento\Framework\AuthorizationFactory;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Integration\Model\CustomUserContext;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use Magento\Webapi\Model\WebapiRoleLocatorFactory;

/**
 * The ACL of one given admin user, whatever area runs the chat. Magento's own AuthorizationInterface
 * follows the admin session in adminhtml, the bearer token in webapi_rest and nobody on the command
 * line, so it cannot answer for the admin a tool call is made on behalf of.
 *
 * The admin user and their role are looked up once per admin and request: a chat turn makes many calls
 * for the same admin. A refused admin user is not remembered and is looked up again on the next call.
 */
class AdminAuthorizationFactory implements ResetAfterRequestInterface
{
    /** @var array<int, AuthorizationInterface> */
    private array $authorizations = [];

    public function __construct(
        private readonly AuthorizationFactory $authorizationFactory,
        private readonly WebapiRoleLocatorFactory $roleLocatorFactory,
        private readonly PolicyInterface $policy,
        private readonly UserCollectionFactory $userCollectionFactory
    ) {
    }

    /**
     * @throws AccessDeniedException When the admin user does not exist or is disabled
     */
    public function create(int $adminUserId): AuthorizationInterface
    {
        return $this->authorizations[$adminUserId] ??= $this->createForActiveAdminUser($adminUserId);
    }

    /**
     * @return void
     */
    public function _resetState(): void
    {
        $this->authorizations = [];
    }

    /**
     * @throws AccessDeniedException
     */
    private function createForActiveAdminUser(int $adminUserId): AuthorizationInterface
    {
        if (!$this->isActiveAdminUser($adminUserId)) {
            throw new AccessDeniedException(__('The admin user is not active.'));
        }

        return $this->authorizationFactory->create([
            'aclPolicy' => $this->policy,
            'roleLocator' => new FixedAclRoleLocator($this->getAclRoleId($adminUserId)),
        ]);
    }

    private function getAclRoleId(int $adminUserId): ?string
    {
        $aclRoleId = $this->roleLocatorFactory->create([
            'userContext' => new CustomUserContext($adminUserId, UserContextInterface::USER_TYPE_ADMIN),
        ])->getAclRoleId();

        return $aclRoleId === null ? null : (string)$aclRoleId;
    }

    private function isActiveAdminUser(int $adminUserId): bool
    {
        if ($adminUserId <= 0) {
            return false;
        }

        return $this->userCollectionFactory->create()
            ->addFieldToFilter('user_id', ['eq' => $adminUserId])
            ->addFieldToFilter('is_active', ['eq' => 1])
            ->getSize() > 0;
    }
}

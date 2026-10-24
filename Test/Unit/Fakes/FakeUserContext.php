<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Authorization\Model\UserContextInterface;

/**
 * The caller of a WebApi request: an admin token unless another user type is given.
 */
final class FakeUserContext implements UserContextInterface
{
    public function __construct(
        private readonly int $userId,
        private readonly int $userType = UserContextInterface::USER_TYPE_ADMIN
    ) {
    }

    public function getUserId()
    {
        return $this->userId;
    }

    public function getUserType()
    {
        return $this->userType;
    }
}

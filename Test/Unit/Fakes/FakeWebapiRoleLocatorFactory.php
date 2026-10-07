<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Webapi\Model\WebapiRoleLocatorFactory;

/**
 * Builds a FakeWebapiRoleLocator for the user context it is handed and counts the role lookups made.
 */
final class FakeWebapiRoleLocatorFactory extends WebapiRoleLocatorFactory
{
    /** @var list<FakeWebapiRoleLocator> */
    private array $locators = [];

    /**
     * @param array<int, string> $roleIdsByUserId
     */
    public function __construct(
        private readonly array $roleIdsByUserId
    ) {
    }

    /**
     * @param array{userContext: UserContextInterface} $data
     */
    public function create(array $data = []): FakeWebapiRoleLocator
    {
        return $this->locators[] = new FakeWebapiRoleLocator($data['userContext'], $this->roleIdsByUserId);
    }

    public function lookups(): int
    {
        return array_sum(array_map(static fn (FakeWebapiRoleLocator $locator): int => $locator->lookups(), $this->locators));
    }
}

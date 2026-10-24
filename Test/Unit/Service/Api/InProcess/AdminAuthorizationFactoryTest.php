<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Api\InProcess;

use MagoAssistant\Mago\Service\Api\InProcess\AccessDeniedException;
use MagoAssistant\Mago\Service\Api\InProcess\AdminAuthorizationFactory;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAclPolicy;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeFrameworkAuthorizationFactory;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeUserCollectionFactory;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeWebapiRoleLocatorFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdminAuthorizationFactoryTest extends TestCase
{
    private const EDITOR_ID = 5;
    private const VIEWER_ID = 6;
    private const INACTIVE_ID = 9;
    private const PRODUCTS = 'Magento_Catalog::products';
    private const ORDERS = 'Magento_Sales::sales_order';

    private FakeUserCollectionFactory $users;
    private FakeWebapiRoleLocatorFactory $roles;
    private AdminAuthorizationFactory $factory;

    protected function setUp(): void
    {
        $this->users = new FakeUserCollectionFactory([self::EDITOR_ID, self::VIEWER_ID]);
        $this->roles = new FakeWebapiRoleLocatorFactory([self::EDITOR_ID => '10', self::VIEWER_ID => '20']);
        $this->factory = new AdminAuthorizationFactory(
            new FakeFrameworkAuthorizationFactory(),
            $this->roles,
            new FakeAclPolicy(['10' => [self::PRODUCTS, self::ORDERS], '20' => [self::ORDERS]]),
            $this->users
        );
    }

    #[Test]
    public function itGivesAnActiveAdminTheResourcesOfTheirRole(): void
    {
        $authorization = $this->factory->create(self::VIEWER_ID);

        self::assertTrue($authorization->isAllowed(self::ORDERS));
        self::assertFalse($authorization->isAllowed(self::PRODUCTS));
    }

    #[Test]
    public function itLooksTheAdminAndTheirRoleUpOncePerRequest(): void
    {
        $first = $this->factory->create(self::EDITOR_ID);
        $first->isAllowed(self::PRODUCTS);
        $second = $this->factory->create(self::EDITOR_ID);
        $second->isAllowed(self::ORDERS);
        $second->isAllowed(self::PRODUCTS);

        self::assertSame($first, $second);
        self::assertSame(1, $this->users->queries());
        self::assertSame(1, $this->roles->lookups());
    }

    #[Test]
    public function itLooksTheAdminUpAgainAfterTheRequestIsReset(): void
    {
        $this->factory->create(self::EDITOR_ID);
        $this->factory->_resetState();
        $this->factory->create(self::EDITOR_ID);

        self::assertSame(2, $this->users->queries());
        self::assertSame(2, $this->roles->lookups());
    }

    #[Test]
    public function itKeepsTheAclsOfDifferentAdminsApart(): void
    {
        $editor = $this->factory->create(self::EDITOR_ID);
        $viewer = $this->factory->create(self::VIEWER_ID);

        self::assertTrue($editor->isAllowed(self::PRODUCTS));
        self::assertFalse($viewer->isAllowed(self::PRODUCTS));
    }

    #[Test]
    public function itRefusesAnInactiveAdminOnEveryCall(): void
    {
        $refusals = array_map(fn (): ?AccessDeniedException => $this->refusalFor(self::INACTIVE_ID), [1, 2]);

        self::assertContainsOnlyInstancesOf(AccessDeniedException::class, $refusals);
        self::assertSame(2, $this->users->queries());
        self::assertSame(0, $this->roles->lookups());
    }

    #[Test]
    public function itRefusesAUserIdOfZeroWithoutAQuery(): void
    {
        $refusal = $this->refusalFor(0);

        self::assertInstanceOf(AccessDeniedException::class, $refusal);
        self::assertSame(0, $this->users->queries());
    }

    private function refusalFor(int $adminUserId): ?AccessDeniedException
    {
        try {
            $this->factory->create($adminUserId);
        } catch (AccessDeniedException $refusal) {
            return $refusal;
        }

        return null;
    }
}

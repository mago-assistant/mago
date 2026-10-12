<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Acl;

use MagoAssistant\Mago\Api\Acl;
use MagoAssistant\Mago\Service\Acl\ToolAccess;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAclAuthorization;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAction;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAuthorization;
use MagoAssistant\Mago\Test\Unit\Fakes\FakePermissionChecker;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeSkill;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #148: a tool declaring no ACL resource was reachable by any admin holding the chat grant.
 * Every call now declares one of three things, and the empty one is the closed one.
 */
final class ToolAccessTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    /**
     * @param string[] $allowedResources What Magento's ACL answers yes to for this admin
     */
    private function access(array $allowedResources, ?FakePermissionChecker $permissions = null): ToolAccess
    {
        return new ToolAccess(new FakeAclAuthorization($allowedResources), $permissions ?? new FakePermissionChecker());
    }

    #[Test]
    public function aMagentoResourceTheAdminHoldsAllowsTheCall(): void
    {
        $tool = new FakeTool('customer_data', ['count'], ['count'], 'Magento_Customer::manage');

        self::assertNull(
            $this->access(['Magento_Customer::manage'])->denialReason($tool, ['action' => 'count'], self::ADMIN_USER_ID)
        );
    }

    #[Test]
    public function aMagentoResourceTheAdminLacksRefusesTheCallAndNamesIt(): void
    {
        $tool = new FakeTool('customer_data', ['count'], ['count'], 'Magento_Customer::manage');

        self::assertSame(
            'Access denied: you do not have the required Magento permission (Magento_Customer::manage) '
                . 'to use the customer_data tool',
            $this->access([])->denialReason($tool, ['action' => 'count'], self::ADMIN_USER_ID)
        );
    }

    /**
     * The empty declaration used to mean "no check"; it now means "nobody", so a tool that forgot
     * cannot open data by accident - whatever the admin holds, Magento-wise or Mago-wise.
     */
    #[Test]
    public function aToolDeclaringNothingIsRefusedForEveryone(): void
    {
        $tool = new FakeTool('forgotten', ['read'], ['read'], '');
        $everything = (new FakePermissionChecker())->withExplicitGrant('forgotten', 'write');

        self::assertSame(
            'Access denied: the forgotten tool declares no ACL resource for this call, so it cannot be used',
            (new ToolAccess(new FakeAuthorization(), $everything))
                ->denialReason($tool, ['action' => 'read'], self::ADMIN_USER_ID)
        );
    }

    #[Test]
    public function aPerUserToolIsRefusedWithoutAnExplicitGrant(): void
    {
        $tool = new FakeTool('issue_tracker', ['list'], ['list'], Acl::MAGO_PER_USER);

        self::assertSame(
            'Access denied: the issue_tracker tool is granted per user, and you have not been given it',
            $this->access([])->denialReason($tool, ['action' => 'list'], self::ADMIN_USER_ID)
        );
    }

    #[Test]
    public function aPerUserToolIsAllowedByAnExplicitGrant(): void
    {
        $tool = new FakeTool('issue_tracker', ['list'], ['list'], Acl::MAGO_PER_USER);
        $granted = (new FakePermissionChecker())->withExplicitGrant('issue_tracker', 'read');

        self::assertNull($this->access([], $granted)->denialReason($tool, ['action' => 'list'], self::ADMIN_USER_ID));
    }

    /**
     * A read grant is not a write grant, the same distinction the Skills screen makes.
     */
    #[Test]
    public function aPerUserReadGrantDoesNotCoverAWrite(): void
    {
        $tool = new FakeTool('issue_tracker', ['create'], [], Acl::MAGO_PER_USER);
        $readOnly = (new FakePermissionChecker())->withExplicitGrant('issue_tracker', 'read');

        self::assertNotNull(
            $this->access([], $readOnly)->denialReason($tool, ['action' => 'create'], self::ADMIN_USER_ID)
        );
    }

    /**
     * The sentinel is not a Magento resource, so Magento is never asked about it - an admin with
     * no Magento resource at all is still fine here once granted the tool.
     */
    #[Test]
    public function aPerUserToolNeverConsultsMagentosAcl(): void
    {
        $tool = new FakeTool('issue_tracker', ['list'], ['list'], Acl::MAGO_PER_USER);
        $granted = (new FakePermissionChecker())->withExplicitGrant('issue_tracker', 'read');
        $magentoDeniesEverything = new FakeAclAuthorization([]);

        self::assertNull(
            (new ToolAccess($magentoDeniesEverything, $granted))
                ->denialReason($tool, ['action' => 'list'], self::ADMIN_USER_ID)
        );
    }

    /**
     * A skill's action may declare a resource of its own on top of the skill's, and both must
     * allow the call: an action narrows what its skill requires, it never replaces it. So
     * top_spenders, which returns customer records, needs the customer register's resource like
     * the rest of customer_data, plus the sales one it declares itself - a role holding only
     * Sales does not get customer PII through it.
     */
    #[Test]
    public function anActionNarrowsItsSkillsResourceAndNeverReplacesIt(): void
    {
        $skill = new FakeSkill('customer_data', new FakeAuthorization(), [
            'count' => new FakeAction('count', true),
            'top_spenders' => new FakeAction('top_spenders', true, aclResource: 'Magento_Sales::sales'),
        ], 'Magento_Customer::manage');

        self::assertSame(
            ['Magento_Customer::manage', 'Magento_Sales::sales'],
            $this->access([])->resourcesFor($skill, ['action' => 'top_spenders'])
        );
        self::assertSame(['Magento_Customer::manage'], $this->access([])->resourcesFor($skill, ['action' => 'count']));

        $salesOnly = $this->access(['Magento_Sales::sales']);
        self::assertStringContainsString(
            'Magento_Customer::manage',
            (string)$salesOnly->denialReason($skill, ['action' => 'top_spenders'], self::ADMIN_USER_ID)
        );

        $customersOnly = $this->access(['Magento_Customer::manage']);
        self::assertNull($customersOnly->denialReason($skill, ['action' => 'count'], self::ADMIN_USER_ID));
        self::assertStringContainsString(
            'Magento_Sales::sales',
            (string)$customersOnly->denialReason($skill, ['action' => 'top_spenders'], self::ADMIN_USER_ID)
        );

        $both = $this->access(['Magento_Customer::manage', 'Magento_Sales::sales']);
        self::assertNull($both->denialReason($skill, ['action' => 'top_spenders'], self::ADMIN_USER_ID));
    }

    /**
     * An action declaring the same resource as its skill is one check, not two.
     */
    #[Test]
    public function anActionRepeatingTheSkillsResourceIsAskedOnce(): void
    {
        $skill = new FakeSkill('product_data', new FakeAuthorization(), [
            'low_stock' => new FakeAction('low_stock', true, aclResource: 'Magento_Catalog::products'),
        ], 'Magento_Catalog::products');

        self::assertSame(['Magento_Catalog::products'], $this->access([])->resourcesFor($skill, ['action' => 'low_stock']));
    }
}

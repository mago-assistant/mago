<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use MagoAssistant\Mago\Service\Skills\PermissionChecker;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAuthorization;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PermissionCheckerTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    /**
     * @param array<string,string> $rows skill_name => permission, as mago_skill_permission holds them
     */
    private function checker(array $rows): PermissionChecker
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchPairs')->willReturn($rows);

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new PermissionChecker($resource, new FakeAuthorization());
    }

    /**
     * isAllowed() falls back to the module-wide assistant grant when no row exists - which the
     * FakeAuthorization answers yes to. isExplicitlyAllowed() does not: holding the assistant at
     * all is not the same as having been given this tool.
     */
    #[Test]
    public function withoutARowTheFallbackAllowsButTheExplicitCheckDoesNot(): void
    {
        $checker = $this->checker([]);

        self::assertTrue($checker->isAllowed(self::ADMIN_USER_ID, 'issue_tracker'));
        self::assertFalse($checker->isExplicitlyAllowed(self::ADMIN_USER_ID, 'issue_tracker'));
    }

    #[Test]
    public function aReadRowAllowsReadsOnly(): void
    {
        $checker = $this->checker(['issue_tracker' => 'read']);

        self::assertTrue($checker->isExplicitlyAllowed(self::ADMIN_USER_ID, 'issue_tracker', 'read'));
        self::assertFalse($checker->isExplicitlyAllowed(self::ADMIN_USER_ID, 'issue_tracker', 'write'));
    }

    #[Test]
    public function aWriteRowAllowsBoth(): void
    {
        $checker = $this->checker(['issue_tracker' => 'write']);

        self::assertTrue($checker->isExplicitlyAllowed(self::ADMIN_USER_ID, 'issue_tracker', 'read'));
        self::assertTrue($checker->isExplicitlyAllowed(self::ADMIN_USER_ID, 'issue_tracker', 'write'));
    }

    #[Test]
    public function aDisabledRowRefusesBothAndBeatsTheFallback(): void
    {
        $checker = $this->checker(['issue_tracker' => 'disabled']);

        self::assertFalse($checker->isAllowed(self::ADMIN_USER_ID, 'issue_tracker'));
        self::assertFalse($checker->isExplicitlyAllowed(self::ADMIN_USER_ID, 'issue_tracker'));
    }
}

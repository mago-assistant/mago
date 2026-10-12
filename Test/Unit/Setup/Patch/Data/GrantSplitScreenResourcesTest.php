<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Setup\Patch\Data;

use Magento\Framework\Acl\Data\CacheInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use MagoAssistant\Mago\Setup\Patch\Data\GrantSplitScreenResources;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GrantSplitScreenResourcesTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private CacheInterface&MockObject $aclCache;

    /**
     * @var array<int, array{string, mixed}>
     */
    private array $roleConditions = [];

    protected function setUp(): void
    {
        $this->roleConditions = [];
        $roleSelect = $this->createMock(Select::class);
        $roleSelect->method('from')->willReturnSelf();
        $roleSelect->method('where')->willReturnCallback(function (string $condition, mixed $value) use ($roleSelect) {
            $this->roleConditions[] = [$condition, $value];

            return $roleSelect;
        });
        $existingSelect = $this->createMock(Select::class);
        $existingSelect->method('from')->willReturnSelf();
        $existingSelect->method('where')->willReturnSelf();

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturnOnConsecutiveCalls($roleSelect, $existingSelect);
        $this->aclCache = $this->createMock(CacheInterface::class);
    }

    /**
     * Every role saved in the role editor has a row for the config resource, a deny one when unticked,
     * so only an allow may count as holding it.
     */
    #[Test]
    public function onlyRolesThatAllowTheConfigResourceAreSelected(): void
    {
        $this->connection->method('fetchCol')->willReturn([]);

        $this->patch()->apply();

        self::assertSame(
            [['resource_id = ?', 'MagoAssistant_Mago::config'], ['permission = ?', 'allow']],
            $this->roleConditions
        );
    }

    #[Test]
    public function aConfigRoleKeepsStatisticsAndSkillsButNotConversations(): void
    {
        $this->connection->method('fetchCol')->willReturn(['4']);
        $this->connection->method('fetchAll')->willReturn([]);
        $this->connection->expects(self::never())->method('delete');
        $this->connection->expects(self::once())->method('insertMultiple')->with(
            'authorization_rule',
            self::callback(static function (array $rows): bool {
                $resources = array_column($rows, 'resource_id');

                return $resources === GrantSplitScreenResources::GRANTED_RESOURCES
                    && !in_array('MagoAssistant_Mago::conversations', $resources, true)
                    && array_unique(array_column($rows, 'role_id')) === [4];
            })
        );
        $this->connection->expects(self::once())->method('commit');
        $this->aclCache->expects(self::once())->method('clean');

        $this->patch()->apply();
    }

    #[Test]
    public function aRowTheRoleAlreadyHasIsLeftAlone(): void
    {
        $this->connection->method('fetchCol')->willReturn(['4']);
        $this->connection->method('fetchAll')->willReturn([
            ['role_id' => '4', 'resource_id' => 'MagoAssistant_Mago::skills_write'],
        ]);
        $this->connection->expects(self::once())->method('insertMultiple')->with(
            'authorization_rule',
            self::callback(static fn(array $rows): bool => !in_array(
                'MagoAssistant_Mago::skills_write',
                array_column($rows, 'resource_id'),
                true
            ) && count($rows) === 3)
        );

        $this->patch()->apply();
    }

    #[Test]
    public function withoutAConfigRoleNothingIsWritten(): void
    {
        $this->connection->method('fetchCol')->willReturn([]);
        $this->connection->expects(self::never())->method('insertMultiple');
        $this->aclCache->expects(self::never())->method('clean');

        $this->patch()->apply();
    }

    private function patch(): GrantSplitScreenResources
    {
        $setup = $this->createMock(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($this->connection);
        $setup->method('getTable')->willReturnArgument(0);

        return new GrantSplitScreenResources($setup, $this->aclCache);
    }
}

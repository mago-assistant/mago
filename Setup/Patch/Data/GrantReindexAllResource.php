<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Setup\Patch\Data;

use Magento\Framework\Acl\Data\CacheInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use MagoAssistant\Mago\Service\Skills\Configuration\IndexerManager;

/**
 * Rebuilding every indexer used to need only Magento_Indexer::invalidate and now needs its own
 * resource as well. A role that held invalidate gets the new resource, so the upgrade takes
 * nothing away silently; an admin can then withdraw it from roles that should not rebuild
 * everything. A role that already has a row for the resource keeps it, so a deny stays a deny.
 */
class GrantReindexAllResource implements DataPatchInterface
{
    private const INVALIDATE_RESOURCE = 'Magento_Indexer::invalidate';

    private const ALLOW = 'allow';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly CacheInterface $aclCache
    ) {
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('authorization_rule');

        $roleIds = $connection->fetchCol(
            $connection->select()
                ->from($table, ['role_id'])
                ->where('resource_id = ?', self::INVALIDATE_RESOURCE)
                ->where('permission = ?', self::ALLOW)
        );
        if ($roleIds === []) {
            return $this;
        }

        $rolesWithARow = array_map('intval', $connection->fetchCol(
            $connection->select()
                ->from($table, ['role_id'])
                ->where('role_id IN (?)', $roleIds)
                ->where('resource_id = ?', IndexerManager::REINDEX_ALL_RESOURCE)
        ));
        $rows = array_map(
            static fn (int $roleId): array => [
                'role_id' => $roleId,
                'resource_id' => IndexerManager::REINDEX_ALL_RESOURCE,
                'permission' => self::ALLOW,
            ],
            array_values(array_diff(array_map('intval', $roleIds), $rolesWithARow))
        );
        if ($rows === []) {
            return $this;
        }

        $connection->insertMultiple($table, $rows);
        $this->aclCache->clean();

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}

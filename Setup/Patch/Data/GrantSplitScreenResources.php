<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Setup\Patch\Data;

use Magento\Framework\Acl\Data\CacheInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Statistics and skill permissions used to sit behind MagoAssistant_Mago::config and now have their
 * own resources (#195). A role that held the config grant keeps them, so the upgrade takes nothing
 * away silently. Conversations are left out on purpose: reading every admin's transcripts with only
 * the config grant was the gap this closed. A role that already has a row for a resource (a role
 * saved after deploy) keeps it, so an explicit deny is never turned into an allow.
 */
class GrantSplitScreenResources implements DataPatchInterface
{
    private const CONFIG_RESOURCE = 'MagoAssistant_Mago::config';

    public const GRANTED_RESOURCES = [
        'MagoAssistant_Mago::statistics',
        'MagoAssistant_Mago::skills',
        'MagoAssistant_Mago::skills_read',
        'MagoAssistant_Mago::skills_write',
    ];

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
                ->where('resource_id = ?', self::CONFIG_RESOURCE)
                ->where('permission = ?', 'allow')
        );
        if ($roleIds === []) {
            return $this;
        }

        $existing = [];
        foreach ($connection->fetchAll(
            $connection->select()
                ->from($table, ['role_id', 'resource_id'])
                ->where('role_id IN (?)', $roleIds)
                ->where('resource_id IN (?)', self::GRANTED_RESOURCES)
        ) as $row) {
            $existing[(int)$row['role_id'] . '|' . $row['resource_id']] = true;
        }

        $rows = [];
        foreach ($roleIds as $roleId) {
            foreach (self::GRANTED_RESOURCES as $resource) {
                if (!isset($existing[(int)$roleId . '|' . $resource])) {
                    $rows[] = ['role_id' => (int)$roleId, 'resource_id' => $resource, 'permission' => 'allow'];
                }
            }
        }
        if ($rows === []) {
            return $this;
        }

        $connection->beginTransaction();
        try {
            $connection->insertMultiple($table, $rows);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
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

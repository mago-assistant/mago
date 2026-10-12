<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;

class PermissionChecker
{
    /** @var array<int, array<string, string>> */
    private array $permissionsByUser = [];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly AuthorizationInterface $authorization
    ) {
    }

    public function isAllowed(int $adminUserId, string $skillName, string $action = 'read'): bool
    {
        $permission = $this->getPermission($adminUserId, $skillName);

        if ($permission !== null) {
            return match ($permission) {
                'write' => true,
                'read' => $action === 'read',
                default => false,
            };
        }

        // Fallback to standard ACL
        if ($action === 'write') {
            return $this->authorization->isAllowed('MagoAssistant_Mago::assistant_write');
        }
        return $this->authorization->isAllowed('MagoAssistant_Mago::assistant_read');
    }

    /**
     * Whether an explicit per-user grant covers this call, without the fallback to the module-wide
     * assistant_read/assistant_write resources that isAllowed() applies. This is the gate for a tool
     * declaring Acl::MAGO_PER_USER: holding the assistant at all is not the same as having been
     * given that tool, so without a row of its own the answer is no.
     */
    public function isExplicitlyAllowed(int $adminUserId, string $skillName, string $action = 'read'): bool
    {
        return match ($this->getPermission($adminUserId, $skillName)) {
            'write' => true,
            'read' => $action === 'read',
            default => false,
        };
    }

    private function getPermission(int $adminUserId, string $skillName): ?string
    {
        if (!isset($this->permissionsByUser[$adminUserId])) {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('mago_skill_permission');

            $this->permissionsByUser[$adminUserId] = $connection->fetchPairs(
                $connection->select()
                    ->from($table, ['skill_name', 'permission'])
                    ->where('admin_user_id = ?', $adminUserId)
            );
        }

        return $this->permissionsByUser[$adminUserId][$skillName] ?? null;
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Phrase;
use MagoAssistant\Mago\Api\Acl;
use MagoAssistant\Mago\Api\Tool\ToolInterface;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;

class SkillEdit extends Template
{
    public function __construct(
        Context $context,
        private readonly ToolRegistry $toolRegistry,
        private readonly ResourceConnection $resourceConnection,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Viewing the permissions and changing them are separate grants (#195).
     */
    public function canEdit(): bool
    {
        return $this->_authorization->isAllowed('MagoAssistant_Mago::skills_write');
    }

    public function getSkill(): ?ToolInterface
    {
        $skillName = $this->getRequest()->getParam('skill_name');
        return $skillName ? $this->toolRegistry->getToolByName($skillName) : null;
    }

    public function getAdminUsers(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $userTable = $this->resourceConnection->getTableName('admin_user');

        $select = $connection->select()
            ->from($userTable, ['user_id', 'username', 'firstname', 'lastname'])
            ->where('is_active = ?', 1)
            ->order('username ASC');

        return $connection->fetchAll($select);
    }

    public function getCurrentPermissions(): array
    {
        $skill = $this->getSkill();
        if (!$skill) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mago_skill_permission');

        $select = $connection->select()
            ->from($table, ['admin_user_id', 'permission'])
            ->where('skill_name = ?', $skill->getName());

        $rows = $connection->fetchAll($select);
        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['admin_user_id']] = $row['permission'];
        }
        return $result;
    }

    /**
     * What the empty option means: a per-user tool is refused until granted, any other tool follows
     * the role's ACL
     */
    public function getDefaultPermissionLabel(): Phrase
    {
        return $this->getSkill()?->getMagentoAcl() === Acl::MAGO_PER_USER
            ? __('Default (not granted)')
            : __('Default (ACL)');
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('mago/skills/savePermissions');
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('mago/skills/index');
    }
}

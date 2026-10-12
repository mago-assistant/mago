<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Controller\Adminhtml\Skills;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\ResultInterface;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;

class SavePermissions extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MagoAssistant_Mago::skills_write';

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resourceConnection,
        private readonly ToolRegistry $toolRegistry,
        private readonly ErrorReporter $errorReporter
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $skillName = $this->getRequest()->getParam('skill_name', '');
        $skillName = is_string($skillName) ? $skillName : '';
        $permissions = $this->getRequest()->getParam('permissions', []);
        $permissions = is_array($permissions) ? $permissions : [];

        if (!$skillName) {
            $this->messageManager->addErrorMessage(__('Skill name is required.'));
            return $this->resultRedirectFactory->create()->setPath('mago/skills/index');
        }

        // A row is the only grant a per-user tool has, so never store one for a name no tool carries
        if ($this->toolRegistry->getToolByName($skillName) === null) {
            $this->messageManager->addErrorMessage(__('Unknown skill "%1".', $skillName));
            return $this->resultRedirectFactory->create()->setPath('mago/skills/index');
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('mago_skill_permission');

            foreach ($permissions as $userId => $permission) {
                $userId = (int)$userId;
                if (!$userId) {
                    continue;
                }

                if ($permission === '' || $permission === null) {
                    $connection->delete($table, [
                        'admin_user_id = ?' => $userId,
                        'skill_name = ?' => $skillName,
                    ]);
                    continue;
                }

                if (!in_array($permission, ['read', 'write', 'disabled'], true)) {
                    continue;
                }

                $connection->insertOnDuplicate($table, [
                    'admin_user_id' => $userId,
                    'skill_name' => $skillName,
                    'permission' => $permission,
                ], ['permission']);
            }

            $this->messageManager->addSuccessMessage(__('Permissions saved for skill "%1".', $skillName));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__(
                'The permissions could not be saved. Reference: %1',
                $this->errorReporter->log('SavePermissions', $e)
            ));
        }

        return $this->resultRedirectFactory->create()->setPath(
            'mago/skills/edit',
            ['skill_name' => $skillName]
        );
    }
}

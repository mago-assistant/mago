<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Controller\Adminhtml\Conversations;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\ResultInterface;
use MagoAssistant\Mago\Service\Error\ErrorReporter;

class MassDelete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MagoAssistant_Mago::conversations_delete';

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resourceConnection,
        private readonly ErrorReporter $errorReporter
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $selected = $this->getRequest()->getParam('selected', []);
        $excluded = $this->getRequest()->getParam('excluded', []);

        $connection = $this->resourceConnection->getConnection();
        $conversationTable = $this->resourceConnection->getTableName('mago_conversation');
        $messageTable = $this->resourceConnection->getTableName('mago_message');
        $usageTable = $this->resourceConnection->getTableName('mago_usage_log');

        if ($excluded === 'false' || $excluded === false) {
            // "Select All" was used — get all IDs from data provider, minus excluded
            $ids = $selected;
        } elseif (!empty($selected)) {
            $ids = $selected;
        } else {
            $this->messageManager->addErrorMessage(__('No conversations selected.'));
            return $this->resultRedirectFactory->create()->setPath('*/*/index');
        }

        $ids = array_map('intval', $ids);
        if (empty($ids)) {
            $this->messageManager->addErrorMessage(__('No conversations selected.'));
            return $this->resultRedirectFactory->create()->setPath('*/*/index');
        }

        try {
            $connection->delete($usageTable, ['conversation_id IN (?)' => $ids]);
            $connection->delete($messageTable, ['conversation_id IN (?)' => $ids]);
            $deleted = $connection->delete($conversationTable, ['entity_id IN (?)' => $ids]);

            $this->messageManager->addSuccessMessage(
                __('A total of %1 conversation(s) have been deleted.', $deleted)
            );
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(
                __(
                    'The conversations could not be deleted. Reference: %1',
                    $this->errorReporter->log('Conversations MassDelete', $e)
                )
            );
        }

        return $this->resultRedirectFactory->create()->setPath('*/*/index');
    }
}

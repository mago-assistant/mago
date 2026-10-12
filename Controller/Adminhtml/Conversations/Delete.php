<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Controller\Adminhtml\Conversations;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\ResultInterface;
use MagoAssistant\Mago\Service\Error\ErrorReporter;

class Delete extends Action implements HttpGetActionInterface
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
        $conversationId = (int)$this->getRequest()->getParam('id');
        if (!$conversationId) {
            $this->messageManager->addErrorMessage(__('Conversation not found.'));
            return $this->resultRedirectFactory->create()->setPath('*/*/index');
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $connection->delete(
                $this->resourceConnection->getTableName('mago_usage_log'),
                ['conversation_id = ?' => $conversationId]
            );
            $connection->delete(
                $this->resourceConnection->getTableName('mago_message'),
                ['conversation_id = ?' => $conversationId]
            );
            $connection->delete(
                $this->resourceConnection->getTableName('mago_conversation'),
                ['entity_id = ?' => $conversationId]
            );

            $this->messageManager->addSuccessMessage(__('Conversation has been deleted.'));
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(
                __(
                    'The conversation could not be deleted. Reference: %1',
                    $this->errorReporter->log('Conversations Delete', $e)
                )
            );
        }

        return $this->resultRedirectFactory->create()->setPath('*/*/index');
    }
}

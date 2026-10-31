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
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Ui\Component\MassAction\Filter;
use MagoAssistant\Mago\Service\Error\ErrorReporter;

class MassDelete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MagoAssistant_Mago::conversations_delete';

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resourceConnection,
        private readonly ErrorReporter $errorReporter,
        private readonly Filter $filter,
        private readonly AbstractDb $gridCollection
    ) {
        parent::__construct($context);
    }

    /**
     * Deletes what the grid selection covers, including "Select All" over the current filters (#310)
     */
    public function execute(): ResultInterface
    {
        $redirect = $this->resultRedirectFactory->create()->setPath('*/*/index');
        $ids = array_map('intval', $this->filter->getCollection($this->gridCollection)->getAllIds());

        if ($ids === []) {
            $this->messageManager->addErrorMessage(__('No conversations selected.'));

            return $redirect;
        }

        try {
            $this->messageManager->addSuccessMessage(
                __('A total of %1 conversation(s) have been deleted.', $this->deleteConversations($ids))
            );
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(
                __(
                    'The conversations could not be deleted. Reference: %1',
                    $this->errorReporter->log('Conversations MassDelete', $e)
                )
            );
        }

        return $redirect;
    }

    /**
     * @param int[] $ids
     */
    private function deleteConversations(array $ids): int
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->delete($this->resourceConnection->getTableName('mago_usage_log'), ['conversation_id IN (?)' => $ids]);
        $connection->delete($this->resourceConnection->getTableName('mago_message'), ['conversation_id IN (?)' => $ids]);

        return $connection->delete($this->resourceConnection->getTableName('mago_conversation'), ['entity_id IN (?)' => $ids]);
    }
}

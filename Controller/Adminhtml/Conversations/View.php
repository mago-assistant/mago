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
use Magento\Framework\View\Result\PageFactory;

class View extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MagoAssistant_Mago::conversations';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly ResourceConnection $resourceConnection
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

        $connection = $this->resourceConnection->getConnection();
        $title = $connection->fetchOne(
            $connection->select()
                ->from($this->resourceConnection->getTableName('mago_conversation'), ['title'])
                ->where('entity_id = ?', $conversationId)
        );

        $resultPage = $this->pageFactory->create();
        $resultPage->setActiveMenu('MagoAssistant_Mago::conversations');
        $resultPage->getConfig()->getTitle()->prepend((string)($title ?: __('Conversation #%1', $conversationId)));
        return $resultPage;
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Controller\Adminhtml\Flags;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;
use MagoAssistant\Mago\Service\Flag\FlagRepository;

class View extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MagoAssistant_Mago::flags';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly FlagRepository $flagRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $flagId = (int)$this->getRequest()->getParam('id');

        if (!$flagId || !$this->flagRepository->getById($flagId)) {
            $this->messageManager->addErrorMessage((string)__('This flagged answer no longer exists.'));

            return $this->resultRedirectFactory->create()->setPath('mago/flags/index');
        }

        $resultPage = $this->pageFactory->create();
        $resultPage->setActiveMenu('MagoAssistant_Mago::flags');
        $resultPage->getConfig()->getTitle()->prepend((string)__('Flagged Answer #%1', $flagId));

        return $resultPage;
    }
}

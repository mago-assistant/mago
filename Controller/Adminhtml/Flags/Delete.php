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
use MagoAssistant\Mago\Service\Flag\FlagRepository;

class Delete extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MagoAssistant_Mago::flags_delete';

    public function __construct(
        Context $context,
        private readonly FlagRepository $flagRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $flagId = (int)$this->getRequest()->getParam('id');

        if ($flagId && $this->flagRepository->delete([$flagId])) {
            $this->messageManager->addSuccessMessage((string)__('The flag was deleted. The conversation is untouched.'));
        }

        return $this->resultRedirectFactory->create()->setPath('mago/flags/index');
    }
}

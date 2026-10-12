<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Controller\Adminhtml\Flags;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use MagoAssistant\Mago\Service\Flag\FlagRepository;

class Delete extends Action implements HttpPostActionInterface
{
    use ReadsConversations;

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

        $redirect = $this->resultRedirectFactory->create()->setPath('mago/flags/index');

        if ($this->flagRepository->delete([$flagId]) === 0) {
            $this->messageManager->addErrorMessage((string)__('This feedback no longer exists.'));

            return $redirect;
        }

        $this->messageManager->addSuccessMessage((string)__('The feedback was deleted. The conversation is untouched.'));

        return $redirect;
    }
}

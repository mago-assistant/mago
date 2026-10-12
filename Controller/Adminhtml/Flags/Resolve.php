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

class Resolve extends Action implements HttpPostActionInterface
{
    use ReadsConversations;

    public const ADMIN_RESOURCE = 'MagoAssistant_Mago::flags';

    public function __construct(
        Context $context,
        private readonly FlagRepository $flagRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $flagId = (int)$this->getRequest()->getParam('id');
        $status = (string)$this->getRequest()->getParam('status', FlagRepository::STATUS_RESOLVED);
        $redirect = $this->resultRedirectFactory->create()->setPath('mago/flags/view', ['id' => $flagId]);

        if ($flagId <= 0 || $this->flagRepository->getById($flagId) === null) {
            $this->messageManager->addErrorMessage((string)__('This feedback no longer exists.'));

            return $redirect->setPath('mago/flags/index');
        }

        try {
            $this->flagRepository->setStatus($flagId, $status);
        } catch (\InvalidArgumentException) {
            $this->messageManager->addErrorMessage((string)__('The feedback status was not changed.'));

            return $redirect;
        }

        $this->messageManager->addSuccessMessage(
            $status === FlagRepository::STATUS_RESOLVED
                ? (string)__('Feedback marked as resolved.')
                : (string)__('Feedback reopened.')
        );

        return $redirect;
    }
}

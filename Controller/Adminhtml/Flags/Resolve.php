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

        if ($flagId && $this->flagRepository->getById($flagId)) {
            $this->flagRepository->setStatus($flagId, $status);
            $this->messageManager->addSuccessMessage(
                $status === FlagRepository::STATUS_RESOLVED
                    ? (string)__('Flag marked as resolved.')
                    : (string)__('Flag reopened.')
            );
        }

        return $this->resultRedirectFactory->create()->setPath('mago/flags/view', ['id' => $flagId]);
    }
}

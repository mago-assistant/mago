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

class MassDelete extends Action implements HttpPostActionInterface
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
        $selected = (array)$this->getRequest()->getParam('selected', []);
        $deleted = $this->flagRepository->delete($selected);

        if ($deleted) {
            $this->messageManager->addSuccessMessage(
                (string)__('%1 flag(s) were deleted. The conversations they came from are untouched.', $deleted)
            );
        }

        return $this->resultRedirectFactory->create()->setPath('mago/flags/index');
    }
}

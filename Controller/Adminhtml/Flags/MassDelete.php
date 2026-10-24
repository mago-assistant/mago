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
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Ui\Component\MassAction\Filter;
use MagoAssistant\Mago\Service\Flag\FlagRepository;

/**
 * The grid's mass action. Filter resolves the selection the way every Magento grid does, so "Select
 * All" (which sends the grid filters and an exclusion list instead of ids) deletes what it shows.
 */
class MassDelete extends Action implements HttpPostActionInterface
{
    use ReadsConversations;

    public const ADMIN_RESOURCE = 'MagoAssistant_Mago::flags_delete';

    public function __construct(
        Context $context,
        private readonly FlagRepository $flagRepository,
        private readonly Filter $filter,
        private readonly AbstractDb $gridCollection
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $deleted = $this->flagRepository->delete($this->filter->getCollection($this->gridCollection)->getAllIds());
        $redirect = $this->resultRedirectFactory->create()->setPath('mago/flags/index');

        if ($deleted === 0) {
            $this->messageManager->addErrorMessage((string)__('No feedback was selected.'));

            return $redirect;
        }

        $this->messageManager->addSuccessMessage(
            (string)__('%1 feedback item(s) were deleted. The conversations they came from are untouched.', $deleted)
        );

        return $redirect;
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Block\Adminhtml\Flag;

use Magento\Backend\Block\Widget\Container as WidgetContainer;
use Magento\Backend\Block\Widget\Context;
use MagoAssistant\Mago\Service\Flag\FlagRepository;

class Container extends WidgetContainer
{
    protected $_template = 'MagoAssistant_Mago::flags/container.phtml';

    public function __construct(
        Context $context,
        private readonly FlagRepository $flagRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _construct(): void
    {
        parent::_construct();

        $flagId = (int)$this->getRequest()->getParam('id');
        $flag = $flagId ? $this->flagRepository->getById($flagId) : null;

        $this->buttonList->add('back', [
            'label' => __('Back'),
            'onclick' => sprintf("setLocation('%s')", $this->getUrl('mago/flags/index')),
            'class' => 'back',
        ]);

        if (!$flag) {
            return;
        }

        if ($this->_authorization->isAllowed('MagoAssistant_Mago::flags_export')) {
            $this->buttonList->add('export', [
                'label' => __('Download JSON'),
                'onclick' => sprintf("setLocation('%s')", $this->getUrl('mago/flags/export', ['id' => $flagId])),
                'class' => 'primary',
            ]);
        }

        $isResolved = ($flag['status'] ?? '') === FlagRepository::STATUS_RESOLVED;
        $this->buttonList->add('resolve', [
            'label' => $isResolved ? __('Reopen') : __('Mark as resolved'),
            'onclick' => sprintf("setLocation('%s')", $this->getUrl('mago/flags/resolve', [
                'id' => $flagId,
                'status' => $isResolved ? FlagRepository::STATUS_OPEN : FlagRepository::STATUS_RESOLVED,
            ])),
            'class' => 'action-secondary',
        ]);

        if ($this->_authorization->isAllowed('MagoAssistant_Mago::flags_delete')) {
            $this->buttonList->add('delete', [
                'label' => __('Delete'),
                'onclick' => sprintf(
                    "confirmSetLocation('%s', '%s')",
                    __('Delete this flag? The conversation it came from is untouched.'),
                    $this->getUrl('mago/flags/delete', ['id' => $flagId])
                ),
                'class' => 'delete',
            ]);
        }
    }
}

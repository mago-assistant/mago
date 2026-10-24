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
            'onclick' => $this->postOnClick($this->getUrl('mago/flags/resolve'), [
                'id' => $flagId,
                'status' => $isResolved ? FlagRepository::STATUS_OPEN : FlagRepository::STATUS_RESOLVED,
            ]),
            'class' => 'action-secondary',
        ]);

        if ($this->_authorization->isAllowed('MagoAssistant_Mago::flags_delete')) {
            $this->buttonList->add('delete', [
                'label' => __('Delete'),
                'onclick' => sprintf(
                    'deleteConfirm(%s, %s, %s)',
                    $this->jsLiteral((string)__('Delete this feedback? The conversation it came from is untouched.')),
                    $this->jsLiteral($this->getUrl('mago/flags/delete')),
                    $this->jsLiteral(['data' => ['id' => $flagId]])
                ),
                'class' => 'delete',
            ]);
        }
    }

    /**
     * Resolve and delete change state, so their controllers only take POST. dataPost submits a form
     * with the form key, the same way Magento's own delete buttons do.
     *
     * @param array<string, int|string> $data
     */
    private function postOnClick(string $url, array $data): string
    {
        return sprintf(
            "require(['mage/dataPost'], function (dataPost) { dataPost().postData(%s); })",
            $this->jsLiteral(['action' => $url, 'data' => $data])
        );
    }

    /**
     * A value as a JavaScript literal that is also safe inside the button's onclick attribute.
     *
     * @param string|array<string, mixed> $value
     */
    private function jsLiteral(string|array $value): string
    {
        return (string)json_encode($value, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
    }
}

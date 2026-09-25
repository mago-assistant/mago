<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Ui\Component\Listing\Column;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class FlagActions extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        private readonly AuthorizationInterface $authorization,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        $canExport = $this->authorization->isAllowed('MagoAssistant_Mago::flags_export');
        $canDelete = $this->authorization->isAllowed('MagoAssistant_Mago::flags_delete');

        foreach ($dataSource['data']['items'] as &$item) {
            if (!isset($item['entity_id'])) {
                continue;
            }

            $actions = [
                'view' => [
                    'href' => $this->urlBuilder->getUrl('mago/flags/view', ['id' => $item['entity_id']]),
                    'label' => __('View'),
                ],
            ];

            if ($canExport) {
                $actions['export'] = [
                    'href' => $this->urlBuilder->getUrl('mago/flags/export', ['id' => $item['entity_id']]),
                    'label' => __('Download JSON'),
                ];
            }

            if ($canDelete) {
                $actions['delete'] = [
                    'href' => $this->urlBuilder->getUrl('mago/flags/delete', ['id' => $item['entity_id']]),
                    'label' => __('Delete'),
                    'confirm' => [
                        'title' => __('Delete Flag'),
                        'message' => __('Delete this flag? The conversation it came from is untouched.'),
                    ],
                ];
            }

            $item[$this->getData('name')] = $actions;
        }

        return $dataSource;
    }
}

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

class ConversationActions extends Column
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
        if (isset($dataSource['data']['items'])) {
            $canDelete = $this->authorization->isAllowed('MagoAssistant_Mago::conversations_delete');

            foreach ($dataSource['data']['items'] as &$item) {
                if (isset($item['entity_id'])) {
                    $actions = [
                        'view' => [
                            'href' => $this->urlBuilder->getUrl(
                                'mago/conversations/view',
                                ['id' => $item['entity_id']]
                            ),
                            'label' => __('View'),
                        ],
                    ];

                    if ($canDelete) {
                        $actions['delete'] = [
                            'href' => $this->urlBuilder->getUrl(
                                'mago/conversations/delete',
                                ['id' => $item['entity_id']]
                            ),
                            'label' => __('Delete'),
                            'post' => true,
                            'confirm' => [
                                'title' => __('Delete Conversation'),
                                'message' => __('Are you sure you want to delete this conversation?'),
                            ],
                        ];
                    }

                    $item[$this->getData('name')] = $actions;
                }
            }
        }

        return $dataSource;
    }
}

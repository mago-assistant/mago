<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Sales\OrderManager\Document;

use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DB\Select;
use Magento\Sales\Model\ResourceModel\Order\Creditmemo\Collection as CreditmemoCollection;
use Magento\Sales\Model\ResourceModel\Order\Creditmemo\CollectionFactory as CreditmemoCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Creditmemo\Item\CollectionFactory as ItemCollectionFactory;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class CreditmemoDocument extends AbstractDocument
{
    private const STATES = [
        'open' => 1,
        'refunded' => 2,
        'canceled' => 3,
    ];

    public function __construct(
        private readonly CreditmemoCollectionFactory $creditmemoCollectionFactory,
        private readonly ItemCollectionFactory $itemCollectionFactory,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getAclResource(): string
    {
        return 'Magento_Sales::sales_creditmemo';
    }

    protected function collection(array $filters): CreditmemoCollection
    {
        $creditmemos = $this->creditmemoCollectionFactory->create();

        $this->filterFields($creditmemos, $filters);
        $this->filterStatus($creditmemos, $filters);
        $this->filterProduct($creditmemos, $filters);

        return $creditmemos;
    }

    private function filterStatus(CreditmemoCollection $creditmemos, array $filters): void
    {
        $status = $filters['document_status'] ?? '';
        if ($status === '') {
            return;
        }

        $state = self::STATES[$status] ?? null;
        if ($state === null) {
            throw new \InvalidArgumentException(
                'Unknown credit memo status "' . $status . '". Use one of: ' . implode(', ', array_keys(self::STATES))
            );
        }

        $creditmemos->addFieldToFilter('main_table.state', ['eq' => $state]);
    }

    private function filterProduct(CreditmemoCollection $creditmemos, array $filters): void
    {
        $product = $filters['product'] ?? '';
        if ($product === '') {
            return;
        }

        $productPattern = '%' . $product . '%';
        $items = $this->itemCollectionFactory->create();
        $items->addFieldToFilter(
            ['sku', 'name'],
            [['like' => $productPattern], ['like' => $productPattern]]
        );

        $itemSelect = $items->getSelect();
        $itemSelect->reset(Select::COLUMNS);
        $itemSelect->columns('parent_id');

        $creditmemos->addFieldToFilter('main_table.entity_id', ['in' => $itemSelect]);
    }

    protected function describe(AbstractDb $creditmemos): array
    {
        $documents = [];
        foreach ($creditmemos as $creditmemo) {
            $document = $creditmemo->getData();
            $entityId = (int)($document['entity_id'] ?? 0);
            $state = array_search(
                (int)($document['state'] ?? 0),
                self::STATES,
                true
            );
            $adminUrl = $this->secureAdminUrl->getUrl('sales/creditmemo/view', ['creditmemo_id' => $entityId]);

            $documents[] = [
                'number' => $document['increment_id'] ?? '',
                'date' => $document['created_at'] ?? '',
                'order_id' => (int)($document['order_id'] ?? 0),
                'state' => $state === false ? 'unknown' : $state,
                'total' => (float)($document['grand_total'] ?? 0),
                'currency' => $document['order_currency_code'] ?? '',
                'admin_url' => $adminUrl,
            ];
        }

        return $documents;
    }
}

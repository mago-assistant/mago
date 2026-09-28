<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Sales\OrderManager\Document;

use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DB\Select;
use Magento\Sales\Model\ResourceModel\Order\Invoice\Collection as InvoiceCollection;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory as InvoiceCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Invoice\Item\CollectionFactory as ItemCollectionFactory;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class InvoiceDocument extends AbstractDocument
{
    private const STATES = [
        'open' => 1,
        'paid' => 2,
        'canceled' => 3,
    ];

    public function __construct(
        private readonly InvoiceCollectionFactory $invoiceCollectionFactory,
        private readonly ItemCollectionFactory $itemCollectionFactory,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getAclResource(): string
    {
        return 'Magento_Sales::sales_invoice';
    }

    protected function collection(array $filters): InvoiceCollection
    {
        $invoices = $this->invoiceCollectionFactory->create();

        $this->filterFields($invoices, $filters);
        $this->filterStatus($invoices, $filters);
        $this->filterProduct($invoices, $filters);

        return $invoices;
    }

    private function filterStatus(InvoiceCollection $invoices, array $filters): void
    {
        $status = $filters['document_status'] ?? '';
        if ($status === '') {
            return;
        }

        $state = self::STATES[$status] ?? null;
        if ($state === null) {
            throw new \InvalidArgumentException(
                'Unknown invoice status "' . $status . '". Use one of: ' . implode(', ', array_keys(self::STATES))
            );
        }

        $invoices->addFieldToFilter('main_table.state', ['eq' => $state]);
    }

    private function filterProduct(InvoiceCollection $invoices, array $filters): void
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

        $invoices->addFieldToFilter('main_table.entity_id', ['in' => $itemSelect]);
    }

    protected function describe(AbstractDb $invoices): array
    {
        $documents = [];
        foreach ($invoices as $invoice) {
            $document = $invoice->getData();
            $entityId = (int)($document['entity_id'] ?? 0);
            $state = array_search(
                (int)($document['state'] ?? 0),
                self::STATES,
                true
            );
            $adminUrl = $this->secureAdminUrl->getUrl('sales/invoice/view', ['invoice_id' => $entityId]);

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

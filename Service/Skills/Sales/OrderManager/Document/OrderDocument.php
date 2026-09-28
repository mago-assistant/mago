<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Sales\OrderManager\Document;

use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DB\Select;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Item\CollectionFactory as ItemCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Payment\CollectionFactory as PaymentCollectionFactory;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class OrderDocument extends AbstractDocument
{
    protected const FIELD_FILTERS = [
        'document_number' => ['increment_id', 'eq'],
        'document_status' => ['status', 'eq'],
        'from' => ['created_at', 'from'],
        'to' => ['created_at', 'to'],
        'min_total' => ['grand_total', 'from'],
        'max_total' => ['grand_total', 'to'],
        'order_ids' => ['entity_id', 'in'],
    ];

    public function __construct(
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly ItemCollectionFactory $itemCollectionFactory,
        private readonly PaymentCollectionFactory $paymentCollectionFactory,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getAclResource(): string
    {
        return 'Magento_Sales::actions_view';
    }

    protected function collection(array $filters): OrderCollection
    {
        $orders = $this->orderCollectionFactory->create();
        $this->joinBillingName($orders);

        $this->filterFields($orders, $filters);
        $this->filterCustomer($orders, $filters);
        $this->filterProduct($orders, $filters);
        $this->filterPayment($orders, $filters);

        return $orders;
    }

    private function joinBillingName(OrderCollection $orders): void
    {
        $orders->getSelect()->joinLeft(
            ['billing_address' => $orders->getTable('sales_order_address')],
            "billing_address.parent_id = main_table.entity_id AND billing_address.address_type = 'billing'",
            [
                'billing_firstname' => 'firstname',
                'billing_lastname' => 'lastname',
            ]
        );
    }

    private function filterCustomer(OrderCollection $orders, array $filters): void
    {
        $customer = $filters['customer'] ?? '';
        if ($customer === '') {
            return;
        }

        if (str_contains($customer, '@')) {
            $orders->addFieldToFilter('main_table.customer_email', ['like' => '%' . $customer . '%']);
            return;
        }

        $nameParts = explode(' ', $customer);
        $lastName = end($nameParts);
        $nameFields = ['main_table.customer_lastname', 'billing_address.lastname'];
        if (count($nameParts) === 1) {
            $nameFields[] = 'main_table.customer_firstname';
            $nameFields[] = 'billing_address.firstname';
        }

        $nameCondition = ['like' => '%' . $lastName . '%'];
        $orders->addFieldToFilter(
            $nameFields,
            array_fill(0, count($nameFields), $nameCondition)
        );
    }

    private function filterProduct(OrderCollection $orders, array $filters): void
    {
        $product = $filters['product'] ?? '';
        if ($product === '') {
            return;
        }

        $productPattern = '%' . $product . '%';
        $items = $this->itemCollectionFactory->create();
        $items->addFieldToFilter('parent_item_id', ['null' => true]);
        $items->addFieldToFilter(
            ['sku', 'name'],
            [['like' => $productPattern], ['like' => $productPattern]]
        );

        $itemSelect = $items->getSelect();
        $itemSelect->reset(Select::COLUMNS);
        $itemSelect->columns('order_id');

        $orders->addFieldToFilter('main_table.entity_id', ['in' => $itemSelect]);
    }

    private function filterPayment(OrderCollection $orders, array $filters): void
    {
        $paymentMethod = $filters['payment_method'] ?? '';
        if ($paymentMethod === '') {
            return;
        }

        $methodPattern = '%' . str_replace(' ', '', $paymentMethod) . '%';
        $payments = $this->paymentCollectionFactory->create();
        $payments->addFieldToFilter('method', ['like' => $methodPattern]);

        $paymentSelect = $payments->getSelect();
        $paymentSelect->reset(Select::COLUMNS);
        $paymentSelect->columns('parent_id');

        $orders->addFieldToFilter('main_table.entity_id', ['in' => $paymentSelect]);
    }

    protected function describe(AbstractDb $orders): array
    {
        $documents = [];
        foreach ($orders as $order) {
            $document = $order->getData();
            $entityId = (int)($document['entity_id'] ?? 0);
            $firstName = $document['customer_firstname'] ?? '';
            $lastName = $document['customer_lastname'] ?? '';
            $customer = trim($firstName . ' ' . $lastName);

            if ($customer === '') {
                $billingFirstName = $document['billing_firstname'] ?? '';
                $billingLastName = $document['billing_lastname'] ?? '';
                $customer = trim($billingFirstName . ' ' . $billingLastName);
            }

            $adminUrl = $this->secureAdminUrl->getUrl('sales/order/view', ['order_id' => $entityId]);

            $documents[] = [
                'order_number' => $document['increment_id'] ?? '',
                'order_id' => $entityId,
                'date' => $document['created_at'] ?? '',
                'status' => $document['status'] ?? '',
                'customer' => $customer,
                'email' => $document['customer_email'] ?? '',
                'total' => (float)($document['grand_total'] ?? 0),
                'currency' => $document['order_currency_code'] ?? '',
                'admin_url' => $adminUrl,
            ];
        }

        return $documents;
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Integration\Service\Skills\Sales\OrderManager;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

class SalesDocumentFixture
{
    private const CURRENCY = 'EUR';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly string $createdAt
    ) {
    }

    public function begin(): void
    {
        $this->connection()->beginTransaction();
    }

    public function rollBack(): void
    {
        $this->connection()->rollBack();
    }

    public function order(string $number, array $data = []): int
    {
        $firstname = $data['firstname'] ?? 'Anna';
        $lastname = $data['lastname'] ?? 'Jansen';

        $orderId = $this->insert('sales_order', [
            'increment_id' => $number,
            'status' => $data['status'] ?? 'processing',
            'state' => $data['status'] ?? 'processing',
            'customer_email' => $data['email'] ?? 'anna.jansen@example.com',
            'customer_firstname' => $firstname,
            'customer_lastname' => $lastname,
            'grand_total' => $data['total'] ?? 100.00,
            'order_currency_code' => self::CURRENCY,
            'created_at' => $this->createdAt,
        ]);

        $this->insert('sales_order_item', [
            'order_id' => $orderId,
            'sku' => $data['sku'] ?? 'FJORD-300',
            'name' => $data['product'] ?? 'Fjordrunner 300',
            'created_at' => $this->createdAt,
        ]);

        $this->insert('sales_order_payment', [
            'parent_id' => $orderId,
            'method' => $data['payment'] ?? 'checkmo',
        ]);

        $this->insert('sales_order_address', [
            'parent_id' => $orderId,
            'address_type' => 'billing',
            'firstname' => $data['billing_firstname'] ?? $firstname,
            'lastname' => $data['billing_lastname'] ?? $lastname,
        ]);

        return $orderId;
    }

    public function invoice(int $orderId, string $number, int $state = 2): int
    {
        $invoiceId = $this->insert('sales_invoice', [
            'order_id' => $orderId,
            'increment_id' => $number,
            'state' => $state,
            'grand_total' => 100.00,
            'order_currency_code' => self::CURRENCY,
            'created_at' => $this->createdAt,
        ]);

        $this->copyItems($orderId, 'sales_invoice_item', $invoiceId);

        return $invoiceId;
    }

    public function shipment(int $orderId, string $number, string $carrier = '', string $trackNumber = ''): int
    {
        $shipmentId = $this->insert('sales_shipment', [
            'order_id' => $orderId,
            'increment_id' => $number,
            'total_qty' => 1,
            'created_at' => $this->createdAt,
        ]);

        $this->copyItems($orderId, 'sales_shipment_item', $shipmentId);

        if ($trackNumber !== '') {
            $this->insert('sales_shipment_track', [
                'parent_id' => $shipmentId,
                'order_id' => $orderId,
                'track_number' => $trackNumber,
                'title' => $carrier,
                'carrier_code' => 'custom',
                'created_at' => $this->createdAt,
            ]);
        }

        return $shipmentId;
    }

    public function creditmemo(int $orderId, string $number, int $state = 2, float $total = 100.00): int
    {
        $creditmemoId = $this->insert('sales_creditmemo', [
            'order_id' => $orderId,
            'increment_id' => $number,
            'state' => $state,
            'grand_total' => $total,
            'order_currency_code' => self::CURRENCY,
            'created_at' => $this->createdAt,
        ]);

        $this->copyItems($orderId, 'sales_creditmemo_item', $creditmemoId);

        return $creditmemoId;
    }

    private function copyItems(int $orderId, string $table, int $documentId): void
    {
        $orderItems = $this->connection()->fetchAll(
            $this->connection()->select()
                ->from($this->resourceConnection->getTableName('sales_order_item'), ['sku', 'name'])
                ->where('order_id = ?', $orderId)
        );

        foreach ($orderItems as $orderItem) {
            $this->insert($table, ['parent_id' => $documentId] + $orderItem);
        }
    }

    private function insert(string $table, array $data): int
    {
        $this->connection()->insert($this->resourceConnection->getTableName($table), $data);

        return (int)$this->connection()->lastInsertId();
    }

    private function connection(): AdapterInterface
    {
        return $this->resourceConnection->getConnection();
    }
}

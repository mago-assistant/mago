<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Integration\Service\Skills\Analytics\SalesData;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Sales\Model\Order;

final class SalesOrderFixture
{
    public const CURRENCY = 'EUR';

    private const TABLE = 'sales_order';
    private const STORE_ID = 1;

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

    public function invoicedOrder(float $subtotal, float $tax, float $shipping, float $globalRate = 1.0): void
    {
        $this->insert(Order::STATE_PROCESSING, $subtotal, $tax, $shipping, 0.0, 0.0, 0.0, $globalRate);
    }

    public function refundedOrder(float $subtotal, float $tax, float $shipping): void
    {
        $this->insert(Order::STATE_CLOSED, $subtotal, $tax, $shipping, $subtotal, $tax, $shipping, 1.0);
    }

    public function canceledOrder(float $subtotal): void
    {
        $this->insert(Order::STATE_CANCELED, $subtotal, 0.0, 0.0, 0.0, 0.0, 0.0, 1.0, false);
    }

    public function unpaidOrder(float $subtotal, string $state): void
    {
        $this->insert($state, $subtotal, 0.0, 0.0, 0.0, 0.0, 0.0, 1.0, false);
    }

    /**
     * An order with the given addresses; null leaves that address out, as a virtual order has no
     * shipping address.
     */
    public function addressedOrder(?string $shippingCountry, ?string $billingCountry): void
    {
        $orderId = $this->insert(Order::STATE_PROCESSING, 10.00, 0.0, 0.0, 0.0, 0.0, 0.0, 1.0);

        foreach (['shipping' => $shippingCountry, 'billing' => $billingCountry] as $type => $country) {
            if ($country !== null) {
                $this->connection()->insert($this->resourceConnection->getTableName('sales_order_address'), [
                    'parent_id' => $orderId,
                    'address_type' => $type,
                    'country_id' => $country,
                ]);
            }
        }
    }

    private function insert(
        string $state,
        float $subtotal,
        float $tax,
        float $shipping,
        float $refundedSubtotal,
        float $refundedTax,
        float $refundedShipping,
        float $globalRate,
        bool $isInvoiced = true
    ): int {
        $grandTotal = $subtotal + $tax + $shipping;
        $refunded = $refundedSubtotal + $refundedTax + $refundedShipping;

        $this->connection()->insert($this->resourceConnection->getTableName(self::TABLE), [
            'state' => $state,
            'status' => $state,
            'store_id' => self::STORE_ID,
            'created_at' => $this->createdAt,
            'order_currency_code' => self::CURRENCY,
            'base_currency_code' => self::CURRENCY,
            'global_currency_code' => self::CURRENCY,
            'base_to_global_rate' => $globalRate,
            'base_to_order_rate' => 1.0,
            'total_item_count' => 1,
            'subtotal' => $subtotal,
            'base_subtotal' => $subtotal,
            'grand_total' => $grandTotal,
            'base_grand_total' => $grandTotal,
            'base_total_invoiced' => $isInvoiced ? $grandTotal : null,
            'base_tax_invoiced' => $isInvoiced ? $tax : null,
            'base_shipping_invoiced' => $isInvoiced ? $shipping : null,
            'base_total_refunded' => $refunded > 0 ? $refunded : null,
            'base_tax_refunded' => $refunded > 0 ? $refundedTax : null,
            'base_shipping_refunded' => $refunded > 0 ? $refundedShipping : null,
        ]);

        return (int)$this->connection()->lastInsertId($this->resourceConnection->getTableName(self::TABLE));
    }

    private function connection(): AdapterInterface
    {
        return $this->resourceConnection->getConnection();
    }
}

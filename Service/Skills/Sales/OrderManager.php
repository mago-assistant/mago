<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Sales;

use MagoAssistant\Mago\Service\Skills\AbstractSkill;

class OrderManager extends AbstractSkill
{
    public function getName(): string
    {
        return 'order_manager';
    }

    protected function getBaseDescription(): string
    {
        return 'Manage orders: add comments, update status, create shipments with tracking, create invoices, process refunds, cancel orders, and resend order confirmations.';
    }

    public function getMagentoAcl(array $input = []): string
    {
        return 'Magento_Sales::sales_order';
    }

    protected function getBaseInstructions(): string
    {
        return 'Always resolve order_number (increment_id like "000000549") to entity_id before API calls. '
            . 'Use sales_data lookup_order if you need to find the entity_id. '
            . 'Order operations are irreversible — always confirm details with the user. '
            . 'After creating a shipment or invoice, include the new entity ID and admin URL in the response. '
            . 'Set notify_customer only when the user asks to inform the customer.';
    }
}

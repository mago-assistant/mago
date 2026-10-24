<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Analytics;

use MagoAssistant\Mago\Service\Skills\AbstractSkill;

class SalesData extends AbstractSkill
{
    public function getName(): string
    {
        return 'sales_data';
    }

    /**
     * Every action here reads sales figures, which is what the Sales menu covers; top_refunded
     * narrows to Magento_Sales::creditmemo on top of it.
     */
    public function getMagentoAcl(array $input = []): string
    {
        return 'Magento_Sales::sales';
    }

    protected function getBaseDescription(): string
    {
        return 'Sales reports over a period: revenue, order counts, top products and top refunded products. '
            . 'For specific orders, invoices, shipments or credit memos use order_manager.';
    }

    protected function getBaseInstructions(): string
    {
        return 'A result about one record carries an admin_url, masked as a token like mago://url_1. Link it only when the answer is about that one record, writing the token exactly as it came back. A count, a total or a list gets no link: there is no single record to open, and a link to nothing in particular is noise under every answer. Never write an admin url yourself, one you assembled is missing the secret key and opens nothing.';
    }
}

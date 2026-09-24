<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Analytics;

use MagoAssistant\Mago\Service\Skills\AbstractSkill;

class CustomerData extends AbstractSkill
{
    public function getName(): string
    {
        return 'customer_data';
    }

    protected function getBaseDescription(): string
    {
        return 'Query customer data: counts, recent signups, top spenders, customer lookup.';
    }

    protected function getBaseInstructions(): string
    {
        return 'A result about one record carries an admin_url, masked as a token like mago://url_1. Link it only when the answer is about that one record, writing the token exactly as it came back. A count, a total or a list gets no link: there is no single record to open, and a link to nothing in particular is noise under every answer. Never write an admin url yourself, one you assembled is missing the secret key and opens nothing.';
    }
}

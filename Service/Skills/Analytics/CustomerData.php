<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Analytics;

use Magento\Framework\AuthorizationInterface;
use MagoAssistant\Mago\Service\Skills\AbstractSkill;
use MagoAssistant\Mago\Service\Url\EntityRouteMap;

class CustomerData extends AbstractSkill
{
    public function __construct(
        AuthorizationInterface $authorization,
        private readonly EntityRouteMap $entityRouteMap,
        array $actions = []
    ) {
        parent::__construct($authorization, $actions);
    }

    public function getName(): string
    {
        return 'customer_data';
    }

    /**
     * Searching, counting and listing the customer register is what the Customers grid shows, so
     * its resource gates it (#148). top_spenders declares its own, which ToolAccess asks first.
     */
    public function getMagentoAcl(array $input = []): string
    {
        return $this->entityRouteMap->getListAclResource('customer') ?? '';
    }

    protected function getBaseDescription(): string
    {
        return 'Query customer data, read only: counts, recent signups, top spenders, customer lookup. '
            . 'The assistant cannot create, edit or delete customers or their addresses, since customer '
            . 'forms hold personal data and are kept out of the assistant. When asked to, say so in your '
            . 'first reply, before asking for any details, and point the administrator to the customer\'s own '
            . 'page under Customers > All Customers, or to Add New Customer for a new one.';
    }

    protected function getBaseInstructions(): string
    {
        return 'A result about one record carries an admin_url, masked as a token like mago://url_1. Link it only when the answer is about that one record, writing the token exactly as it came back. A count, a total or a list gets no link: there is no single record to open, and a link to nothing in particular is noise under every answer. Never write an admin url yourself, one you assembled is missing the secret key and opens nothing.';
    }
}

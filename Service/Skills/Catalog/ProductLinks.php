<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog;

use MagoAssistant\Mago\Service\Skills\AbstractSkill;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\LinkRequest;

class ProductLinks extends AbstractSkill
{
    public function getName(): string
    {
        return 'product_links';
    }

    protected function getBaseDescription(): string
    {
        return 'Read and change the related, up-sell and cross-sell products of products, also in bulk.';
    }

    public function getMagentoAcl(array $input = []): string
    {
        return 'Magento_Catalog::products';
    }

    protected function getBaseInstructions(): string
    {
        return 'Related products show on the product page, up-sells show on the product page as better '
            . 'alternatives, cross-sells show in the cart. Work with SKUs only: find the products with '
            . 'product_data.search first and never guess a SKU. Read the current links with get_links when the '
            . 'request depends on them. One add_links or remove_links call takes at most '
            . LinkRequest::MAX_PRODUCTS . ' products and ' . LinkRequest::MAX_LINKED_SKUS . ' linked SKUs; '
            . 'split a larger change into several calls and say so. Every write shows the admin what changes '
            . 'before it runs, so propose it directly instead of asking first. Grouped product children, '
            . 'bundle options and configurable variants are not links here. get_links returns an admin_url, '
            . 'masked as a token like mago://url_1: link it only when the answer is about that one product, '
            . 'writing the token exactly as it came back. Never write an admin url yourself.';
    }
}

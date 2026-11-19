<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog;

use MagoAssistant\Mago\Service\Skills\AbstractSkill;

/**
 * Looks at and changes the filters on category and search pages: which attributes are filters,
 * whether they help shoppers in a category, and their options
 */
class LayeredNavigation extends AbstractSkill
{
    /** Stores > Attributes > Product, where the same settings are made by hand */
    public const ACL_ATTRIBUTES = 'Magento_Catalog::attributes_attributes';

    public function getName(): string
    {
        return 'layered_navigation';
    }

    public function getMagentoAcl(array $input = []): string
    {
        return self::ACL_ATTRIBUTES;
    }

    protected function getBaseDescription(): string
    {
        return 'Layered navigation filters on category and search pages: which product attributes are filters, '
            . 'how useful each is in a category, and their options. Changes filter settings and cleans up options.';
    }

    protected function getBaseInstructions(): string
    {
        return 'Find the attribute code with list_filters first; never guess it. A changed filter or option '
            . 'shows on the storefront after a reindex and a cache flush: after a change, offer indexer_manager '
            . 'for the catalog_product_attribute and catalogsearch_fulltext indexers and cache_manager, do not run '
            . 'them unasked.';
    }
}

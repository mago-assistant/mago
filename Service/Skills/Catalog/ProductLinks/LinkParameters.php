<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks;

use MagoAssistant\Mago\Service\Skills\ResultLimit;

/**
 * The parameter schemas the product_links actions share. The skill merges the schemas of its
 * actions and the first declaration of a name wins, so every action declares a shared parameter
 * from here, with one description that serves all of them.
 */
final class LinkParameters
{
    /**
     * @return array<string, mixed>
     */
    public static function sku(): array
    {
        return [
            'type' => 'string',
            'description' => 'get_links, set_positions: SKU of the product whose links to read or reorder.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function skus(): array
    {
        return [
            'type' => 'array',
            'items' => ['type' => 'string'],
            'maxItems' => LinkRequest::MAX_PRODUCTS,
            'description' => 'add_links, remove_links: SKUs of the products whose links change (max '
                . LinkRequest::MAX_PRODUCTS . '). Find them with product_data.search first.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function linkedSkus(): array
    {
        return [
            'type' => 'array',
            'items' => ['type' => 'string'],
            'maxItems' => LinkRequest::MAX_LINKED_SKUS,
            'description' => 'add_links: SKUs to link to every product in skus. remove_links: SKUs to unlink; '
                . 'omit to remove every link of link_type. set_positions: linked SKUs in their new order, first '
                . 'shown first; links not listed follow in their current order. Max '
                . LinkRequest::MAX_LINKED_SKUS . '.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function linkType(): array
    {
        return [
            'type' => 'string',
            'enum' => LinkType::values(),
            'description' => 'related (related products on the product page), upsell (better alternatives on the '
                . 'product page) or crosssell (suggestions in the cart). Required for writes; get_links reads all '
                . 'three when omitted.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function twoWay(): array
    {
        return [
            'type' => 'boolean',
            'description' => 'add_links, remove_links: also link (or unlink) each linked SKU back to the product. '
                . 'Not for upsell. Default false.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function limit(): array
    {
        return [
            'type' => 'integer',
            'minimum' => 1,
            'maximum' => ResultLimit::MAX,
            'description' => 'get_links: maximum number of links to list. Default ' . ResultLimit::MAX . '.',
        ];
    }
}

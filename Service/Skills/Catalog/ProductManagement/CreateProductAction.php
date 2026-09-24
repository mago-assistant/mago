<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductManagement;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Api\InternalApiClient;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class CreateProductAction implements ActionInterface
{
    private const TYPES = ['simple', 'virtual', 'downloadable', 'configurable', 'grouped', 'bundle'];

    public function __construct(
        private readonly InternalApiClient $apiClient,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'create_product';
    }

    public function getDescription(): string
    {
        return 'Create a new catalog product (simple, virtual, downloadable, configurable, grouped or bundle). '
            . 'Pass only data the user gave; do not fill gaps with invented values, the result reports what is missing.';
    }

    public function getParameterSchema(): array
    {
        return [
            'sku' => [
                'type' => 'string',
                'description' => 'Unique SKU. When the user gives none, derive one from the name (uppercase, dashes) and mention it.',
            ],
            'name' => [
                'type' => 'string',
                'description' => 'Product name',
            ],
            'product_type' => [
                'type' => 'string',
                'enum' => self::TYPES,
                'description' => 'Product type. Use "simple" unless the request clearly implies another type; do not ask.',
            ],
            'price' => [
                'type' => 'number',
                'description' => 'Price in base currency. Omit for grouped and dynamic-price bundle products.',
            ],
            'attribute_set_id' => [
                'type' => 'integer',
                'description' => 'Attribute set ID, from list_attribute_sets when the user names a set. Omit for the default set.',
            ],
            'qty' => [
                'type' => 'number',
                'description' => 'Initial stock quantity (simple/virtual/downloadable only). ONLY if the user stated a quantity; never guess one.',
            ],
            'weight' => [
                'type' => 'number',
                'description' => 'Weight for simple products. ONLY if the user stated it; omit otherwise.',
            ],
            'enabled' => [
                'type' => 'boolean',
                'description' => 'Whether the product is enabled (default true)',
            ],
            'visible' => [
                'type' => 'boolean',
                'description' => 'Visible in catalog and search (default true). Set false for configurable child products.',
            ],
            'description' => [
                'type' => 'string',
                'description' => 'Full product description (HTML allowed). ONLY text the user supplied; never write marketing copy yourself.',
            ],
            'short_description' => [
                'type' => 'string',
                'description' => 'Short description. ONLY text the user supplied; omit otherwise.',
            ],
            'url_key' => [
                'type' => 'string',
                'description' => 'URL key; generated from name when omitted',
            ],
            'category_ids' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
                'description' => 'Category IDs to assign the product to. ONLY if the user named categories; omit otherwise.',
            ],
            'website_ids' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
                'description' => 'Website IDs (default: [1])',
            ],
            'custom_attributes' => [
                'type' => 'object',
                'description' => 'Extra attribute values keyed by attribute code, e.g. {"tax_class_id": "2"}. '
                    . 'Omit when not needed. Never set variant attributes (color, size) on a configurable parent; '
                    . 'those belong to the children created by add_configurable_variants.',
            ],
            'ignore_similar' => [
                'type' => 'boolean',
                'description' => 'Set true to create even though similar products were reported on the previous attempt.',
            ],
            'grouped_skus' => [
                'type' => 'array',
                'items' => ['type' => 'object'],
                'description' => 'Grouped products only: [{"sku": "child-sku", "qty": 1}]',
            ],
            'bundle_options' => [
                'type' => 'array',
                'items' => ['type' => 'object'],
                'description' => 'Bundle products only: [{"title": "Option", "type": "select", "required": true, '
                    . '"products": [{"sku": "child-sku", "qty": 1, "is_default": true}]}]',
            ],
            'bundle_price_type' => [
                'type' => 'string',
                'enum' => ['dynamic', 'fixed'],
                'description' => 'Bundle products only: dynamic (price from selections, default) or fixed',
            ],
            'downloadable_links' => [
                'type' => 'array',
                'items' => ['type' => 'object'],
                'description' => 'Downloadable products only: [{"title": "File", "url": "https://...", "price": 0, '
                    . '"number_of_downloads": 0}]',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return 'Magento_Catalog::products';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function getFieldClassification(): array
    {
        // Catalog data only; name/type sit under similar_products. The _links url is now a signed
        // admin url, so it holds the secret key and crosses masked as a token; the panel swaps the
        // real url back in on display.
        return [
            'success' => [PiiClass::PUBLIC],
            'message' => [PiiClass::PUBLIC],
            'sku' => [PiiClass::PUBLIC],
            'id' => [PiiClass::PUBLIC],
            'missing' => [PiiClass::PUBLIC],
            'next_step' => [PiiClass::PUBLIC],
            'hint' => [PiiClass::PUBLIC],
            'not_created' => [PiiClass::PUBLIC],
            'name' => [PiiClass::PUBLIC],
            'type' => [PiiClass::PUBLIC],
            'label' => [PiiClass::PUBLIC],
            'url' => [PiiClass::TOKENISE, 'url'],
        ];
    }

    public function getInstructions(): string
    {
        return <<<'TEXT'
Only pass values the user actually gave. Never invent qty, weight, descriptions, categories or
other attributes to make the product look complete; leave them out and the store's defaults apply.
Deriving a SKU from the name (uppercase, dashes) and assuming product_type "simple" is fine, say so.
The result lists what was left empty under "missing" — repeat that to the user so they can follow up.
Required: sku, name, product_type. Price is required for simple, virtual, downloadable, configurable
and fixed-price bundle products. Grouped products and dynamic bundles derive price from children —
child products must exist first (create them with separate create_product calls).
Configurable parents are created without variants; add them afterwards with add_configurable_variants.
Downloadable links with type "url" are used; for uploaded files the admin must use the admin panel.
TEXT;
    }

    public function execute(array $params, int $adminUserId): array
    {
        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $sku = trim((string)($params['sku'] ?? ''));
        $name = trim((string)($params['name'] ?? ''));
        $type = (string)($params['product_type'] ?? '');

        if (!$sku || !$name) {
            return ['error' => 'Both sku and name are required'];
        }
        if (!in_array($type, self::TYPES, true)) {
            return ['error' => 'Invalid product_type. Allowed: ' . implode(', ', self::TYPES)];
        }

        if ($this->productExists($sku, $adminUserId)) {
            return [
                'error' => 'A product with SKU "' . $sku . '" already exists.',
                'hint' => 'Do not invent a variant SKU. Tell the user it exists and ask whether they meant this product '
                    . '(they may want to edit it) or a new one with a different SKU.',
                '_links' => [
                    [
                        'label' => 'Edit existing ' . $sku,
                        'url' => $this->secureAdminUrl->getUrl(
                            'catalog/product/edit',
                            ['id' => $this->findIdBySku($sku, $adminUserId) ?? '']
                        ),
                    ],
                ],
            ];
        }

        if (empty($params['ignore_similar'])) {
            $similar = $this->findSimilar($name, $adminUserId);
            if ($similar) {
                return [
                    'not_created' => true,
                    'similar_products' => $similar,
                    'hint' => 'Products with a similar name already exist. Ask the user whether to create "' . $name
                        . '" anyway; if yes, call create_product again with ignore_similar: true.',
                ];
            }
        }

        $priceType = $params['bundle_price_type'] ?? 'dynamic';
        $priceless = $type === 'grouped' || ($type === 'bundle' && $priceType === 'dynamic');
        if (!$priceless && !isset($params['price'])) {
            return ['error' => 'price is required for this product type'];
        }

        $product = [
            'sku' => $sku,
            'name' => $name,
            'type_id' => $type,
            'attribute_set_id' => (int)($params['attribute_set_id'] ?? 4),
            'status' => ($params['enabled'] ?? true) ? 1 : 2,
            'visibility' => ($params['visible'] ?? true) ? 4 : 1,
            'extension_attributes' => [
                'website_ids' => array_map('intval', $params['website_ids'] ?? [1]),
            ],
            'custom_attributes' => [],
        ];

        if (!$priceless) {
            $product['price'] = (float)$params['price'];
        }

        if ($type === 'simple') {
            $product['weight'] = (float)($params['weight'] ?? 1);
        }

        if (in_array($type, ['simple', 'virtual', 'downloadable'], true)) {
            $qty = (float)($params['qty'] ?? 0);
            $product['extension_attributes']['stock_item'] = [
                'qty' => $qty,
                'is_in_stock' => $qty > 0,
            ];
        }

        foreach ($params['category_ids'] ?? [] as $position => $categoryId) {
            $product['extension_attributes']['category_links'][] = [
                'position' => $position,
                'category_id' => (string)$categoryId,
            ];
        }

        $customAttributes = [
            'url_key' => $params['url_key'] ?? $this->generateUrlKey($name),
        ];
        foreach (['description', 'short_description'] as $attr) {
            if (!empty($params[$attr])) {
                $customAttributes[$attr] = $params[$attr];
            }
        }
        foreach ($params['custom_attributes'] ?? [] as $code => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $customAttributes[(string)$code] = $value;
        }
        foreach ($customAttributes as $code => $value) {
            $product['custom_attributes'][] = ['attribute_code' => $code, 'value' => $value];
        }

        if ($type === 'grouped') {
            $links = $this->buildGroupedLinks($sku, $params['grouped_skus'] ?? [], $adminUserId);
            if (isset($links['error'])) {
                return $links;
            }
            $product['product_links'] = $links;
        }

        if ($type === 'bundle') {
            $options = $this->buildBundleOptions($params['bundle_options'] ?? [], $adminUserId);
            if (isset($options['error'])) {
                return $options;
            }
            $product['extension_attributes']['bundle_product_options'] = $options;
            $product['custom_attributes'][] = [
                'attribute_code' => 'price_type',
                'value' => $priceType === 'fixed' ? '1' : '0',
            ];
            $product['custom_attributes'][] = ['attribute_code' => 'price_view', 'value' => '0'];
        }

        if ($type === 'downloadable') {
            $links = $this->buildDownloadableLinks($params['downloadable_links'] ?? []);
            if (isset($links['error'])) {
                return $links;
            }
            $product['extension_attributes']['downloadable_product_links'] = $links;
            $product['custom_attributes'][] = ['attribute_code' => 'links_purchased_separately', 'value' => '0'];
        }

        $result = $this->apiClient->post('products', ['product' => $product], $adminUserId);

        if (isset($result['error'])) {
            return ['error' => 'Failed to create product: ' . $result['error']];
        }

        $response = [
            'success' => true,
            'message' => ucfirst($type) . ' product "' . $name . '" created with SKU "' . $sku . '"',
            'sku' => $result['sku'] ?? $sku,
            'id' => $result['id'] ?? null,
            '_links' => [
                [
                    'label' => 'Edit ' . $name,
                    'url' => $this->secureAdminUrl->getUrl('catalog/product/edit', ['id' => $result['id'] ?? '']),
                ],
            ],
        ];

        $missing = $this->collectMissing($type, $params);
        if ($missing) {
            $response['missing'] = $missing;
        }

        if ($type === 'configurable') {
            $response['next_step'] = 'REQUIRED: the parent has no variants yet. Call product_management with '
                . 'action "add_configurable_variants" for SKU "' . $sku . '" now, in this same turn. '
                . 'Do not describe this step to the user, just do it.';
        }

        return $response;
    }

    /**
     * Loose duplicate guard: a merchant re-adding "Green Mug" usually means the product exists under a
     * SKU they forgot. Name substring match, capped small, never blocks when the user insists.
     *
     * @return array<int,array{sku:string,name:string,type:string}>
     */
    private function findSimilar(string $name, int $adminUserId): array
    {
        $result = $this->apiClient->get(
            'products',
            $this->apiClient->buildSearchCriteria([
                ['field' => 'name', 'value' => '%' . addcslashes($name, '%_') . '%', 'condition_type' => 'like'],
            ], 5),
            $adminUserId
        );

        $similar = [];
        foreach ($result['items'] ?? [] as $item) {
            $similar[] = [
                'sku' => (string)($item['sku'] ?? ''),
                'name' => (string)($item['name'] ?? ''),
                'type' => (string)($item['type_id'] ?? ''),
            ];
        }

        return $similar;
    }

    private function missingChildrenMessage(array $missing): string
    {
        return 'These child products do not exist yet: ' . implode(', ', $missing)
            . '. Offer to create them as simple products (name and price needed for each), then create the parent again.';
    }

    private function findIdBySku(string $sku, int $adminUserId): ?int
    {
        $result = $this->apiClient->get('products/' . urlencode($sku), [], $adminUserId);

        return isset($result['id']) ? (int)$result['id'] : null;
    }

    private function productExists(string $sku, int $adminUserId): bool
    {
        $result = $this->apiClient->get('products/' . urlencode($sku), [], $adminUserId);

        return !isset($result['error']);
    }

    private function buildGroupedLinks(string $parentSku, array $groupedSkus, int $adminUserId): array
    {
        if (!$groupedSkus) {
            return ['error' => 'grouped_skus is required for grouped products'];
        }

        $links = [];
        $missing = [];
        foreach ($groupedSkus as $position => $child) {
            $childSku = trim((string)($child['sku'] ?? ''));
            if (!$childSku) {
                return ['error' => 'Each grouped_skus entry needs a sku'];
            }
            if (!$this->productExists($childSku, $adminUserId)) {
                $missing[] = $childSku;
                continue;
            }
            $links[] = [
                'sku' => $parentSku,
                'link_type' => 'associated',
                'linked_product_sku' => $childSku,
                'linked_product_type' => 'simple',
                'position' => $position,
                'extension_attributes' => ['qty' => (float)($child['qty'] ?? 1)],
            ];
        }
        if ($missing) {
            return ['error' => $this->missingChildrenMessage($missing)];
        }

        return $links;
    }

    private function buildBundleOptions(array $bundleOptions, int $adminUserId): array
    {
        if (!$bundleOptions) {
            return ['error' => 'bundle_options is required for bundle products'];
        }

        $options = [];
        $missing = [];
        foreach ($bundleOptions as $index => $option) {
            $title = trim((string)($option['title'] ?? ''));
            $products = $option['products'] ?? [];
            if (!$title || !$products) {
                return ['error' => 'Each bundle option needs a title and at least one product'];
            }

            $selections = [];
            foreach ($products as $child) {
                $childSku = trim((string)($child['sku'] ?? ''));
                if (!$childSku) {
                    return ['error' => 'Each bundle option product needs a sku'];
                }
                if (!$this->productExists($childSku, $adminUserId)) {
                    $missing[] = $childSku;
                    continue;
                }
                $selections[] = [
                    'sku' => $childSku,
                    'option_id' => 0,
                    'qty' => (float)($child['qty'] ?? 1),
                    'position' => count($selections),
                    'is_default' => (bool)($child['is_default'] ?? false),
                    'price' => (float)($child['price'] ?? 0),
                    'price_type' => 0,
                    'can_change_quantity' => 1,
                ];
            }

            $options[] = [
                'option_id' => 0,
                'title' => $title,
                'required' => (bool)($option['required'] ?? true),
                'type' => (string)($option['type'] ?? 'select'),
                'position' => $index,
                'sku' => '',
                'product_links' => $selections,
            ];
        }
        if ($missing) {
            return ['error' => $this->missingChildrenMessage(array_unique($missing))];
        }

        return $options;
    }

    private function buildDownloadableLinks(array $downloadableLinks): array
    {
        if (!$downloadableLinks) {
            return ['error' => 'downloadable_links is required for downloadable products'];
        }

        $links = [];
        foreach ($downloadableLinks as $position => $link) {
            $url = trim((string)($link['url'] ?? ''));
            if (!$url) {
                return ['error' => 'Each downloadable link needs a url'];
            }
            $links[] = [
                'title' => (string)($link['title'] ?? 'Download'),
                'sort_order' => $position,
                'is_shareable' => 0,
                'price' => (float)($link['price'] ?? 0),
                'number_of_downloads' => (int)($link['number_of_downloads'] ?? 0),
                'link_type' => 'url',
                'link_url' => $url,
            ];
        }

        return $links;
    }

    /**
     * Fields a merchant almost always wants but that a short chat request rarely contains. Reported
     * back so the assistant tells the user instead of quietly shipping a half-configured product.
     *
     * @return string[]
     */
    private function collectMissing(string $type, array $params): array
    {
        $missing = [];
        if (in_array($type, ['simple', 'virtual', 'downloadable'], true) && (float)($params['qty'] ?? 0) <= 0) {
            $missing[] = 'qty (product is out of stock)';
        }
        if ($type === 'simple' && !isset($params['weight'])) {
            $missing[] = 'weight (defaulted to 1)';
        }
        if (empty($params['description']) && empty($params['short_description'])) {
            $missing[] = 'description';
        }
        if (empty($params['category_ids'])) {
            $missing[] = 'categories';
        }
        $missing[] = 'images (upload via the admin form)';

        return $missing;
    }

    private function generateUrlKey(string $name): string
    {
        $key = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
        return $key ?: uniqid('product-');
    }
}

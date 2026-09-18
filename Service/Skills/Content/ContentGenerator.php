<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Content;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use MagoAssistant\Mago\Api\Tool\ToolInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Store\StoreScopeContext;

class ContentGenerator implements ToolInterface
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StoreScopeContext $scopeContext
    ) {
    }

    public function getName(): string
    {
        return 'content_generator';
    }

    public function getDescription(): string
    {
        return 'Generate product content (descriptions, meta tags). Returns generated text for merchant review before saving. Actions: "generate_description", "generate_meta", "generate_short_description".';
    }

    public function getParameterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['generate_description', 'generate_meta', 'generate_short_description'],
                ],
                'sku' => [
                    'type' => 'string',
                    'description' => 'Product SKU to generate content for',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'The generated content to save (used when confirming a save)',
                ],
                'instructions' => [
                    'type' => 'string',
                    'description' => 'Additional instructions for content generation (tone, keywords, length)',
                ],
                'store_id' => [
                    'type' => 'integer',
                    'description' => 'Scope of the content: 0 = default values shared by all store views (default). '
                        . 'Pass a store view id from the store scope list to read and save a store-view-specific '
                        . 'version, e.g. a translation.',
                ],
            ],
            'required' => ['action', 'sku'],
        ];
    }

    public function execute(array $params): array
    {
        $action = $params['action'] ?? '';
        $sku = $params['sku'] ?? '';

        if (!$sku) {
            return ['error' => 'SKU is required'];
        }

        $storeId = (int)($params['store_id'] ?? 0);
        if ($storeId !== 0 && !$this->scopeContext->hasStoreView($storeId)) {
            return ['error' => $this->scopeContext->getUnknownStoreViewError($storeId)];
        }
        $scopeLabel = $storeId === 0
            ? 'default scope (shared by all store views)'
            : $this->scopeContext->describeStoreTarget($storeId);

        try {
            $product = $this->productRepository->get($sku, false, $storeId);
        } catch (\Throwable $e) {
            return ['error' => 'Product not found: ' . $sku];
        }

        // If content is provided, this is a save action
        if (!empty($params['content'])) {
            return $this->saveContent($product, $action, $params['content'], $storeId) + [
                'store_id' => $storeId,
                'scope_label' => $scopeLabel,
            ];
        }

        // Otherwise return product context for the AI to generate content
        $context = [
            'store_id' => $storeId,
            'scope_label' => $scopeLabel,
            'sku' => $product->getSku(),
            'name' => $product->getName(),
            'price' => (float)$product->getPrice(),
            'type' => $product->getTypeId(),
            'current_description' => $product->getDescription() ?? '',
            'current_short_description' => $product->getShortDescription() ?? '',
            'current_meta_title' => $product->getMetaTitle() ?? '',
            'current_meta_description' => $product->getMetaDescription() ?? '',
            'url_key' => $product->getUrlKey() ?? '',
        ];

        // Get category names
        $categoryNames = [];
        $categories = $product->getCategoryCollection()->addAttributeToSelect('name');
        foreach ($categories as $category) {
            $categoryNames[] = $category->getName();
        }
        $context['categories'] = $categoryNames;

        return [
            'action' => $action,
            'product_context' => $context,
            'instructions' => $params['instructions'] ?? '',
            'message' => 'Use this product context to generate the requested content. Return the content and ask the merchant to confirm before saving.',
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function isReadOnlyAction(array $input): bool
    {
        return $this->isReadOnly();
    }

    public function getInstructions(): string
    {
        return 'Content is read and saved at the default scope unless store_id names a store view. On a multi-store '
            . 'installation, ask whether the text is for all store views or for one store view (for example a '
            . 'translation) when the user did not say. Use the same store_id for the generate call and the save call, '
            . 'and repeat the scope_label in your answer.';
    }

    public function getFieldClassification(string $action = ''): array
    {
        return [
            'action' => [PiiClass::PUBLIC],
            'success' => [PiiClass::PUBLIC],
            'sku' => [PiiClass::PUBLIC],
            'field' => [PiiClass::PUBLIC],
            'message' => [PiiClass::PUBLIC],
            'note' => [PiiClass::PUBLIC],
            'store_id' => [PiiClass::PUBLIC],
            'scope_label' => [PiiClass::PUBLIC],
            'store_label' => [PiiClass::PUBLIC],
            'name' => [PiiClass::PUBLIC],
            'price' => [PiiClass::PUBLIC],
            'type' => [PiiClass::PUBLIC],
            'current_description' => [PiiClass::PUBLIC],
            'current_short_description' => [PiiClass::PUBLIC],
            'current_meta_title' => [PiiClass::PUBLIC],
            'current_meta_description' => [PiiClass::PUBLIC],
            'url_key' => [PiiClass::PUBLIC],
            'categories' => [PiiClass::PUBLIC],
            'instructions' => [PiiClass::PUBLIC],
        ];
    }

    public function getMagentoAcl(array $input = []): string
    {
        return 'Magento_Catalog::products';
    }

    private function saveContent(ProductInterface $product, string $action, string $content, int $storeId): array
    {
        $field = match ($action) {
            'generate_description' => 'description',
            'generate_short_description' => 'short_description',
            'generate_meta' => 'meta_description',
            default => null,
        };

        if (!$field) {
            return ['error' => 'Unknown action: ' . $action];
        }

        $product->setData($field, $content);
        $this->productRepository->save($product);

        $result = [
            'success' => true,
            'sku' => $product->getSku(),
            'field' => $field,
            'message' => sprintf('Updated %s for product %s', $field, $product->getSku()),
        ];

        if ($storeId === 0 && !$this->scopeContext->hasSingleStoreView()) {
            $overriddenIn = $this->findStoreViewOverrides((string)$product->getSku(), $field, $content);
            if ($overriddenIn !== []) {
                $result['overridden_in'] = $overriddenIn;
                $result['note'] = 'These store views keep their own value for this field, so the new default text is '
                    . 'not visible there. Tell the user, and offer to save the text for those store views too '
                    . '(same call with their store_id).';
            }
        }

        return $result;
    }

    /**
     * Store views that still show a different value after a default-scope save.
     *
     * Earlier versions of this tool saved at the admin's current store view instead of the default
     * scope, so shops upgraded from them carry store-level values that mask a new default text.
     *
     * @return array<int, array{store_id:int,store_label:string}>
     */
    private function findStoreViewOverrides(string $sku, string $field, string $content): array
    {
        $overriddenIn = [];
        foreach (array_keys($this->scopeContext->getStoreViews()) as $storeViewId) {
            try {
                $storeProduct = $this->productRepository->get($sku, false, $storeViewId, true);
            } catch (\Throwable) {
                continue;
            }
            if ((string)$storeProduct->getData($field) !== $content) {
                $overriddenIn[] = [
                    'store_id' => $storeViewId,
                    'store_label' => $this->scopeContext->describeStoreTarget($storeViewId),
                ];
            }
        }

        return $overriddenIn;
    }
}

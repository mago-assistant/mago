<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Skills\ResultLimit;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;

class GetLinksAction implements ActionInterface
{
    private const STATUS_ENABLED = 'enabled';
    private const STATUS_DISABLED = 'disabled';

    public function __construct(
        private readonly ProductLinkGatewayInterface $gateway,
        private readonly SecureAdminUrl $secureAdminUrl
    ) {
    }

    public function getName(): string
    {
        return 'get_links';
    }

    public function getDescription(): string
    {
        return 'The related, up-sell and cross-sell products of one product (sku), in position order, with a '
            . 'total per link type. link_type limits it to one type';
    }

    public function getParameterSchema(): array
    {
        return [
            'sku' => LinkParameters::sku(),
            'link_type' => LinkParameters::linkType(),
            'limit' => LinkParameters::limit(),
        ];
    }

    public function getAclResource(): ?string
    {
        return null;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function getFieldClassification(): array
    {
        return [
            'sku' => [PiiClass::PUBLIC],
            'name' => [PiiClass::PUBLIC],
            'status' => [PiiClass::PUBLIC],
            'admin_url' => [PiiClass::TOKENISE, 'url'],
            'link_type' => [PiiClass::PUBLIC],
            'count' => [PiiClass::PUBLIC],
            'shown' => [PiiClass::PUBLIC],
            'is_partial' => [PiiClass::PUBLIC],
            'position' => [PiiClass::PUBLIC],
        ];
    }

    public function getInstructions(): string
    {
        return 'Answer with the totals per link type, then a short table per type with SKU, name, status and '
            . 'position. A disabled linked product does not show on the storefront: point those out. When '
            . 'is_partial is true, say the table is cut and give the full counts from totals.';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $request = LinkRequest::fromParams($params);
        if (count($request->productSkus) !== 1) {
            return ['error' => 'sku is required: the SKU of one product.'];
        }
        if ($request->hasRequestedType() && $request->type === null) {
            return ['error' => (string)$request->findTypeRefusal()];
        }

        $product = $this->gateway->findProducts($request->productSkus)->find($request->productSkus[0]);
        if ($product === null) {
            return ['error' => 'Unknown SKU: ' . $request->productSkus[0]
                . '. Look the product up with product_data.search; never guess a SKU.'];
        }

        $types = $request->type === null ? LinkType::cases() : [$request->type];
        $links = array_merge([], ...array_map(
            fn (LinkType $type): array => $this->gateway->findLinks([$product->sku], $type),
            $types
        ));
        $limit = ResultLimit::fromParams($params, ResultLimit::MAX);
        $shown = array_slice($links, 0, $limit);

        return [
            'sku' => $product->sku,
            'name' => $product->name,
            'status' => $this->status($product),
            'admin_url' => $this->secureAdminUrl->getUrl('catalog/product/edit', ['id' => $product->id]),
            'totals' => array_map(
                static fn (LinkType $type): array => [
                    'link_type' => $type->value,
                    'count' => count(array_filter($links, static fn (ProductLink $link): bool => $link->type === $type)),
                ],
                $types
            ),
            'shown' => count($shown),
            'is_partial' => count($links) > count($shown),
            'links' => $this->rows($shown),
        ];
    }

    /**
     * @param list<ProductLink> $links
     * @return list<array<string, mixed>>
     */
    private function rows(array $links): array
    {
        $linkedProducts = $this->gateway->findProducts(array_map(
            static fn (ProductLink $link): string => $link->linkedSku,
            $links
        ));

        return array_map(
            fn (ProductLink $link): array => [
                'link_type' => $link->type->value,
                'sku' => $link->linkedSku,
                'name' => $linkedProducts->find($link->linkedSku)->name ?? '',
                'status' => $this->status($linkedProducts->find($link->linkedSku)),
                'position' => $link->position,
            ],
            $links
        );
    }

    private function status(?CatalogProduct $product): string
    {
        return $product?->isEnabled === true ? self::STATUS_ENABLED : self::STATUS_DISABLED;
    }
}

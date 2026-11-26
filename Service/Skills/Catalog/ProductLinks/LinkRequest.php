<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks;

use LogicException;

/**
 * The tool input of a product_links call, read once. Holds the checks that need no catalog
 * lookup; the planner does the rest.
 */
final readonly class LinkRequest
{
    public const MAX_PRODUCTS = 50;
    public const MAX_LINKED_SKUS = 20;

    /**
     * @param list<string> $productSkus
     * @param list<string> $linkedSkus
     */
    private function __construct(
        public array $productSkus,
        public array $linkedSkus,
        public ?LinkType $type,
        private string $requestedType,
        public bool $isTwoWay
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     */
    public static function fromParams(array $params): self
    {
        $requestedType = is_scalar($params['link_type'] ?? null) ? strtolower(trim((string)$params['link_type'])) : '';

        return new self(
            self::skuList([...self::rawSkus($params['sku'] ?? null), ...self::rawSkus($params['skus'] ?? null)]),
            self::skuList(self::rawSkus($params['linked_skus'] ?? null)),
            LinkType::tryFrom($requestedType),
            $requestedType,
            filter_var($params['two_way'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false
        );
    }

    public function hasRequestedType(): bool
    {
        return $this->requestedType !== '';
    }

    /**
     * The link type, for callers past findTypeRefusal()
     */
    public function linkType(): LinkType
    {
        return $this->type ?? throw new LogicException('No valid link_type: ' . $this->findTypeRefusal());
    }

    public function findTypeRefusal(): ?string
    {
        return match (true) {
            $this->type !== null => null,
            $this->requestedType === '' => 'link_type is required: one of ' . $this->typeList() . '.',
            $this->requestedType === LinkType::ASSOCIATED => 'Grouped product children (associated) are not '
                . 'managed by product_links: they decide what the grouped product sells. Change them on the '
                . 'grouped product\'s edit page.',
            default => 'Unknown link_type "' . $this->requestedType . '". Use one of ' . $this->typeList() . '.',
        };
    }

    /**
     * The checks every add_links and remove_links call passes before the catalog is read
     */
    public function findBulkWriteRefusal(): ?string
    {
        return $this->findTypeRefusal()
            ?? $this->findProductCountRefusal()
            ?? $this->findLinkedCountRefusal()
            ?? $this->findTwoWayRefusal();
    }

    public function findLinkedSkusMissingRefusal(): ?string
    {
        return $this->linkedSkus === [] ? 'linked_skus is required: the SKUs to link.' : null;
    }

    public function findSingleProductRefusal(): ?string
    {
        return count($this->productSkus) === 1
            ? null
            : 'Give exactly one product in sku: positions are set per product.';
    }

    public function findLinkedCountRefusal(): ?string
    {
        return count($this->linkedSkus) > self::MAX_LINKED_SKUS
            ? 'At most ' . self::MAX_LINKED_SKUS . ' linked_skus per call, ' . count($this->linkedSkus)
                . ' were given. Split the change into several calls.'
            : null;
    }

    /**
     * Every SKU the call names, products first
     *
     * @return list<string>
     */
    public function allSkus(): array
    {
        return self::skuList([...$this->productSkus, ...$this->linkedSkus]);
    }

    private function findProductCountRefusal(): ?string
    {
        if ($this->productSkus === []) {
            return 'skus is required: the SKUs of the products to change.';
        }

        return count($this->productSkus) > self::MAX_PRODUCTS
            ? 'At most ' . self::MAX_PRODUCTS . ' products per call, ' . count($this->productSkus)
                . ' were given. Split the change into several calls.'
            : null;
    }

    private function findTwoWayRefusal(): ?string
    {
        return $this->isTwoWay && $this->type?->canLinkBothWays() === false
            ? 'two_way is not available for up-sells: an up-sell points at a better product, and two products '
                . 'cannot each be the better one.'
            : null;
    }

    private function typeList(): string
    {
        return implode(', ', LinkType::values());
    }

    /**
     * @return list<string>
     */
    private static function rawSkus(mixed $value): array
    {
        return match (true) {
            is_array($value) => array_values(array_map(
                static fn (mixed $sku): string => (string)$sku,
                array_filter($value, is_scalar(...))
            )),
            is_scalar($value) => explode(',', (string)$value),
            default => [],
        };
    }

    /**
     * Trimmed, without blanks or case-insensitive repeats, in the order given
     *
     * @param list<string> $skus
     * @return list<string>
     */
    private static function skuList(array $skus): array
    {
        return array_values(array_reduce(
            array_filter(array_map(trim(...), $skus), static fn (string $sku): bool => $sku !== ''),
            static fn (array $unique, string $sku): array => $unique + [CatalogProducts::key($sku) => $sku],
            []
        ));
    }
}

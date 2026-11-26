<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks;

/**
 * Works out the writes of add_links, remove_links and set_positions from the current links, or the
 * reason the call would be refused. The card, the refusal and the save all call this, so what the
 * administrator confirms is what gets written.
 */
class LinkPlanner
{
    public function __construct(
        private readonly ProductLinkGatewayInterface $gateway
    ) {
    }

    public function planAdditions(LinkRequest $request): LinkPlan
    {
        $refusal = $request->findBulkWriteRefusal() ?? $request->findLinkedSkusMissingRefusal();
        if ($refusal !== null) {
            return LinkPlan::refused($refusal);
        }

        $products = $this->gateway->findProducts($request->allSkus());
        $refusal = $this->findUnknownSkuRefusal($request, $products);
        if ($refusal !== null) {
            return LinkPlan::refused($refusal);
        }

        $pairs = $this->requestedPairs($request, $products);
        if ($pairs === []) {
            return LinkPlan::refused('A product cannot be linked to itself.');
        }

        $changes = $this->additions($request->linkType(), $pairs, $this->currentLinks($pairs, $request->linkType()));

        return $changes->isEmpty()
            ? LinkPlan::refused('Nothing to add: every requested ' . $request->linkType()->label() . ' link already exists.')
            : LinkPlan::of($changes, $products);
    }

    public function planRemovals(LinkRequest $request): LinkPlan
    {
        $refusal = $request->findBulkWriteRefusal();
        if ($refusal !== null) {
            return LinkPlan::refused($refusal);
        }

        $products = $this->gateway->findProducts($request->allSkus());
        $refusal = $this->findUnknownSkuRefusal($request, $products);
        if ($refusal !== null) {
            return LinkPlan::refused($refusal);
        }

        $changes = $this->removals($request, $products);
        if ($changes->isEmpty()) {
            return LinkPlan::refused('Nothing to remove: none of these ' . $request->linkType()->label() . ' links exist.');
        }

        return LinkPlan::of($changes, $this->gateway->findProducts([...$request->allSkus(), ...$changes->productSkus()]));
    }

    public function planPositions(LinkRequest $request): LinkPlan
    {
        $refusal = $request->findTypeRefusal()
            ?? $request->findSingleProductRefusal()
            ?? $request->findLinkedSkusMissingRefusal()
            ?? $request->findLinkedCountRefusal();
        if ($refusal !== null) {
            return LinkPlan::refused($refusal);
        }

        $products = $this->gateway->findProducts($request->allSkus());
        $refusal = $this->findUnknownSkuRefusal($request, $products);
        if ($refusal !== null) {
            return LinkPlan::refused($refusal);
        }

        $productSku = $this->canonical($request->productSkus[0], $products);
        $current = $this->gateway->findLinks([$productSku], $request->linkType());
        $refusal = $this->findNotLinkedRefusal($request, $productSku, $current);
        if ($refusal !== null) {
            return LinkPlan::refused($refusal);
        }

        $changes = new LinkChangeSet($request->linkType(), $this->repositioned($request->linkedSkus, $current));

        return $changes->isEmpty()
            ? LinkPlan::refused('The ' . $request->linkType()->label() . ' products of ' . $productSku
                . ' are already in this order.')
            : LinkPlan::of($changes, $products);
    }

    private function findUnknownSkuRefusal(LinkRequest $request, CatalogProducts $products): ?string
    {
        $missing = $products->missingFrom($request->allSkus());

        return $missing === []
            ? null
            : 'Unknown SKU: ' . implode(', ', $missing) . '. Look the products up with product_data.search; '
                . 'never guess a SKU.';
    }

    /**
     * @param list<ProductLink> $current
     */
    private function findNotLinkedRefusal(LinkRequest $request, string $productSku, array $current): ?string
    {
        $notLinked = array_values(array_filter(
            $request->linkedSkus,
            fn (string $sku): bool => $this->findPointingAt($sku, $current) === null
        ));

        return $notLinked === []
            ? null
            : 'Not linked as ' . $request->linkType()->label() . ' to ' . $productSku . ': '
                . implode(', ', $notLinked) . '. Add them with add_links first, or leave them out.';
    }

    /**
     * Each product to each linked SKU, and back when two-way; never a product to itself
     *
     * @return list<ProductLink>
     */
    private function requestedPairs(LinkRequest $request, CatalogProducts $products): array
    {
        $forward = array_merge([], ...array_map(
            fn (string $productSku): array => array_map(
                fn (string $linkedSku): ProductLink => new ProductLink(
                    $this->canonical($productSku, $products),
                    $request->linkType(),
                    $this->canonical($linkedSku, $products),
                    0
                ),
                $request->linkedSkus
            ),
            $request->productSkus
        ));
        $pairs = $request->isTwoWay
            ? [...$forward, ...array_map(static fn (ProductLink $link): ProductLink => $link->reversed(), $forward)]
            : $forward;

        return $this->unique(array_values(array_filter(
            $pairs,
            static fn (ProductLink $link): bool => !$link->pointsAt($link->productSku)
        )));
    }

    /**
     * A link that exists is skipped and keeps its position; a new one goes after the last
     *
     * @param list<ProductLink> $pairs
     * @param list<ProductLink> $current
     */
    private function additions(LinkType $type, array $pairs, array $current): LinkChangeSet
    {
        $currentByPair = array_combine(
            array_map(static fn (ProductLink $link): string => $link->pairKey(), $current),
            $current
        );
        $lastPositions = $this->lastPositions($current);
        $saves = [];
        $skipped = [];
        foreach ($pairs as $pair) {
            $existing = $currentByPair[$pair->pairKey()] ?? null;
            if ($existing !== null) {
                $skipped[] = $existing;
                continue;
            }
            $owner = CatalogProducts::key($pair->productSku);
            $lastPositions[$owner] = ($lastPositions[$owner] ?? 0) + 1;
            $saves[] = $pair->withPosition($lastPositions[$owner]);
        }

        return new LinkChangeSet($type, $saves, [], $skipped);
    }

    private function removals(LinkRequest $request, CatalogProducts $products): LinkChangeSet
    {
        $type = $request->linkType();
        $productSkus = array_map(fn (string $sku): string => $this->canonical($sku, $products), $request->productSkus);
        $current = $this->gateway->findLinks($productSkus, $type);
        $deletes = [];
        $skipped = [];
        foreach ($productSkus as $productSku) {
            $own = array_values(array_filter($current, static fn (ProductLink $link): bool => $link->isFrom($productSku)));
            if ($request->linkedSkus === []) {
                $deletes = [...$deletes, ...$own];
                continue;
            }
            foreach ($request->linkedSkus as $linkedSku) {
                $link = $this->findPointingAt($linkedSku, $own);
                if ($link === null) {
                    $skipped[] = new ProductLink($productSku, $type, $this->canonical($linkedSku, $products), 0);
                    continue;
                }
                $deletes[] = $link;
            }
        }

        $deletes = $request->isTwoWay ? [...$deletes, ...$this->reverseLinksOf($deletes, $type)] : $deletes;

        return new LinkChangeSet($type, [], $this->unique($deletes), $skipped);
    }

    /**
     * The links that point back at the product of each given link
     *
     * @param list<ProductLink> $links
     * @return list<ProductLink>
     */
    private function reverseLinksOf(array $links, LinkType $type): array
    {
        if ($links === []) {
            return [];
        }

        $linkedSkus = array_values(array_unique(array_map(static fn (ProductLink $link): string => $link->linkedSku, $links)));

        return array_values(array_filter(
            $this->gateway->findLinks($linkedSkus, $type),
            static fn (ProductLink $candidate): bool => array_filter(
                $links,
                static fn (ProductLink $link): bool => $candidate->isReverseOf($link)
            ) !== []
        ));
    }

    /**
     * The listed links first, in the order given, then the others in their current order; only the
     * links whose position changes are returned
     *
     * @param list<string> $linkedSkus
     * @param list<ProductLink> $current
     * @return list<ProductLink>
     */
    private function repositioned(array $linkedSkus, array $current): array
    {
        $listed = array_values(array_filter(array_map(
            fn (string $sku): ?ProductLink => $this->findPointingAt($sku, $current),
            $linkedSkus
        )));
        $listedKeys = array_map(static fn (ProductLink $link): string => $link->pairKey(), $listed);
        $rest = array_values(array_filter(
            $current,
            static fn (ProductLink $link): bool => !in_array($link->pairKey(), $listedKeys, true)
        ));
        $ordered = array_map(
            static fn (ProductLink $link, int $index): ProductLink => $link->withPosition($index + 1),
            [...$listed, ...$rest],
            array_keys([...$listed, ...$rest])
        );

        return array_values(array_filter(
            $ordered,
            static fn (ProductLink $link): bool => $link->position !== self::currentPosition($link, $current)
        ));
    }

    /**
     * @param list<ProductLink> $current
     */
    private static function currentPosition(ProductLink $link, array $current): ?int
    {
        foreach ($current as $candidate) {
            if ($candidate->pairKey() === $link->pairKey()) {
                return $candidate->position;
            }
        }

        return null;
    }

    /**
     * @param list<ProductLink> $pairs
     * @return list<ProductLink>
     */
    private function currentLinks(array $pairs, LinkType $type): array
    {
        $owners = array_values(array_unique(array_map(static fn (ProductLink $link): string => $link->productSku, $pairs)));

        return $this->gateway->findLinks($owners, $type);
    }

    /**
     * @param list<ProductLink> $links
     * @return array<string, int> highest position per product key
     */
    private function lastPositions(array $links): array
    {
        return array_reduce(
            $links,
            static fn (array $last, ProductLink $link): array => [
                CatalogProducts::key($link->productSku) => max(
                    $last[CatalogProducts::key($link->productSku)] ?? 0,
                    $link->position
                ),
            ] + $last,
            []
        );
    }

    /**
     * @param list<ProductLink> $links
     */
    private function findPointingAt(string $sku, array $links): ?ProductLink
    {
        foreach ($links as $link) {
            if ($link->pointsAt($sku)) {
                return $link;
            }
        }

        return null;
    }

    /**
     * @param list<ProductLink> $links
     * @return list<ProductLink>
     */
    private function unique(array $links): array
    {
        return array_values(array_reduce(
            $links,
            static fn (array $unique, ProductLink $link): array => $unique + [$link->pairKey() => $link],
            []
        ));
    }

    private function canonical(string $sku, CatalogProducts $products): string
    {
        return $products->find($sku)?->sku ?? $sku;
    }
}

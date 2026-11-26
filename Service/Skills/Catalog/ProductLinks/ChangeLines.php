<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks;

/**
 * The confirmation card lines of a planned write: one summary line, then one line per product
 * naming every SKU it gains, loses or moves.
 */
class ChangeLines
{
    /**
     * @return list<string>
     */
    public function forAdditions(LinkPlan $plan): array
    {
        $changes = $plan->changes();

        return [
            'Adds ' . $this->countOf(count($changes->saves), $changes->type->label() . ' link') . ' to '
                . $this->countOf(count($changes->changedProductSkus()), 'product')
                . '. The storefront shows them right away.',
            ...array_map(
                fn (string $sku): string => $this->productLine(
                    $plan->products->labelOf($sku),
                    ['adds' => LinkChangeSet::linkedSkusFrom($sku, $changes->saves),
                        'already linked' => LinkChangeSet::linkedSkusFrom($sku, $changes->skipped)]
                ),
                $changes->productSkus()
            ),
        ];
    }

    /**
     * @return list<string>
     */
    public function forRemovals(LinkPlan $plan): array
    {
        $changes = $plan->changes();

        return [
            'Removes ' . $this->countOf(count($changes->deletes), $changes->type->label() . ' link') . ' from '
                . $this->countOf(count($changes->changedProductSkus()), 'product')
                . '. Their positions are lost; a link added again goes to the end of the list.',
            ...array_map(
                fn (string $sku): string => $this->productLine(
                    $plan->products->labelOf($sku),
                    ['removes' => LinkChangeSet::linkedSkusFrom($sku, $changes->deletes),
                        'not linked' => LinkChangeSet::linkedSkusFrom($sku, $changes->skipped)]
                ),
                $changes->productSkus()
            ),
        ];
    }

    /**
     * @return list<string>
     */
    public function forPositions(LinkPlan $plan): array
    {
        $changes = $plan->changes();
        $productSku = $changes->changedProductSkus()[0] ?? '';

        return [
            'Changes the order of the ' . $changes->type->label() . ' products of '
                . $plan->products->labelOf($productSku) . ':',
            implode(', ', array_map(
                static fn (ProductLink $link): string => $link->linkedSku . ' to position ' . $link->position,
                $changes->saves
            )) . '.',
        ];
    }

    /**
     * @param array<string, list<string>> $skusByVerb
     */
    private function productLine(string $productLabel, array $skusByVerb): string
    {
        $named = array_filter($skusByVerb, static fn (array $skus): bool => $skus !== []);

        return $productLabel . ': ' . implode('; ', array_map(
            static fn (string $verb, array $skus): string => $verb . ' ' . implode(', ', $skus),
            array_keys($named),
            $named
        )) . '.';
    }

    private function countOf(int $count, string $noun): string
    {
        return $count . ' ' . $noun . ($count === 1 ? '' : 's');
    }
}

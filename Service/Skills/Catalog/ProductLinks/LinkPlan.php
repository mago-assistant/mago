<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks;

use LogicException;

/**
 * What a write call would do, or why it would not: the refusal, or the changes and the products
 * they touch.
 */
final readonly class LinkPlan
{
    private function __construct(
        private ?string $refusal,
        private ?LinkChangeSet $changes,
        public CatalogProducts $products
    ) {
    }

    public static function refused(string $reason): self
    {
        return new self($reason, null, new CatalogProducts([]));
    }

    public static function of(LinkChangeSet $changes, CatalogProducts $products): self
    {
        return new self(null, $changes, $products);
    }

    public function refusal(): ?string
    {
        return $this->refusal;
    }

    public function changes(): LinkChangeSet
    {
        return $this->changes ?? throw new LogicException('A refused plan has no changes: ' . $this->refusal);
    }
}

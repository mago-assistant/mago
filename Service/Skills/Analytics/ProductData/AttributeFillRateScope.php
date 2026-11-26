<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Analytics\ProductData;

/**
 * Which products and which values an attribute fill rate reads. Store id 0 reads the default scope
 * values of every product; a store view reads its own values with the default as fallback, for the
 * products in its website.
 */
final readonly class AttributeFillRateScope
{
    public const DEFAULT_STORE_ID = 0;

    public function __construct(
        public int $storeId = self::DEFAULT_STORE_ID,
        public int $websiteId = 0,
        public ?int $attributeSetId = null,
        public bool $isOnlyEnabled = false,
        public bool $isIncludingSystem = false
    ) {
    }

    public function isStoreView(): bool
    {
        return $this->storeId !== self::DEFAULT_STORE_ID;
    }
}

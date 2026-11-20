<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

/**
 * A data interface the manifest drafter reads getters from.
 */
interface FakeFaqDataInterface
{
    public function getFaqId(): ?int;

    public function getQuestion(): string;

    public function getAnswer(): string;

    public function getIsActive(): bool;

    public function getProductSkus(): array;

    public function getCreatedAt(): ?string;

    public function getVatId(): string;

    /**
     * Typed in the docblock only, as most of Magento's data interfaces are.
     *
     * @return string|null
     */
    public function getNote();

    /**
     * @return \MagoAssistant\Mago\Test\Unit\Fakes\FakeFaqDataInterface[]
     */
    public function getChildren();

    public function get3dSecure(): bool;

    public function getExtensionAttributes(): ?object;

    public function getStoreIds(int $websiteId): array;
}

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

    public function getExtensionAttributes(): ?object;

    public function getStoreIds(int $websiteId): array;
}

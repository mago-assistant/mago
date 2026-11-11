<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

/**
 * A service contract the manifest drafter reflects on.
 */
interface FakeFaqRepositoryInterface
{
    public function get(int $faqId): FakeFaqDataInterface;

    public function getList(): array;

    public function save(FakeFaqDataInterface $entity): FakeFaqDataInterface;

    public function deleteById(int $faqId): bool;

    public function publish(int $faqId, string $note = '', ?bool $notify = null): FakeFaqDataInterface;
}

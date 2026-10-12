<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Sales\OrderManager;

use Magento\Framework\DB\Select;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\Document\DocumentList;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\Document\DocumentTypeInterface;

final class RecordingDocumentType implements DocumentTypeInterface
{
    private array $recordedFilters = [];

    private string $failure = '';

    public function __construct(
        private readonly string $aclResource,
        private readonly Select $select
    ) {
    }

    public function givenListFails(string $failure): void
    {
        $this->failure = $failure;
    }

    public function getAclResource(): string
    {
        return $this->aclResource;
    }

    public function list(array $filters): DocumentList
    {
        $this->recordedFilters[] = $filters;
        if ($this->failure !== '') {
            throw new \InvalidArgumentException($this->failure);
        }

        return new DocumentList(new FakeDocumentCollection($this->select), fn(): array => []);
    }

    public function recordedFilters(): array
    {
        return $this->recordedFilters;
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Sales\OrderManager\Document;

use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DB\Select;

class DocumentList
{
    private array $includes = [];

    private string $sort = 'newest';

    public function __construct(
        private readonly AbstractDb $collection,
        private readonly \Closure $describe,
        private readonly string $orderIdField = 'main_table.order_id',
        private readonly array $sorts = []
    ) {
    }

    public function size(): int
    {
        return $this->collection->getSize();
    }

    public function documents(?int $limit = null): array
    {
        [$sortColumn, $sortDirection] = $this->sorts[$this->sort] ?? ['created_at', 'DESC'];
        $collection = clone $this->collection;
        $collection->setOrder('main_table.' . $sortColumn, $sortDirection);
        if ($limit !== null) {
            $collection->setPageSize($limit);
        }

        $documents = ($this->describe)($collection);
        foreach ($this->includes as $name => $documentType) {
            $documents = $this->withIncluded($documents, $name, $documentType);
        }

        return $documents;
    }

    public function sortedBy(string $sort): self
    {
        $documentList = clone $this;
        $documentList->sort = $sort;

        return $documentList;
    }

    public function include(string $name, DocumentTypeInterface $documentType): self
    {
        $documentList = clone $this;
        $documentList->includes[$name] = $documentType;

        return $documentList;
    }

    public function inOrdersOf(DocumentList $related, bool $exclude = false): self
    {
        $condition = $exclude ? 'nin' : 'in';
        $collection = clone $this->collection;
        $collection->addFieldToFilter($this->orderIdField, [$condition => $related->orderIds()]);

        return new self($collection, $this->describe, $this->orderIdField, $this->sorts);
    }

    private function withIncluded(array $documents, string $name, DocumentTypeInterface $documentType): array
    {
        $orderIds = array_column($documents, 'order_id');
        $includedList = $documentType->list(['order_ids' => $orderIds]);

        $includedPerOrder = [];
        foreach ($includedList->documents() as $includedDocument) {
            $includedPerOrder[$includedDocument['order_id']][] = $includedDocument;
        }

        foreach ($documents as $index => $document) {
            $documents[$index][$name] = $includedPerOrder[$document['order_id']] ?? [];
        }

        return $documents;
    }

    private function orderIds(): Select
    {
        $select = clone $this->collection->getSelect();
        $select->reset(Select::COLUMNS);
        $select->columns($this->orderIdField);

        return $select;
    }
}

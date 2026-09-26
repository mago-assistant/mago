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
    public function __construct(
        private readonly AbstractDb $collection,
        private readonly \Closure $describe,
        private readonly string $orderIdField = 'main_table.order_id'
    ) {
    }

    public function size(): int
    {
        return $this->collection->getSize();
    }

    public function documents(?int $limit = null): array
    {
        $collection = clone $this->collection;
        $collection->setOrder('main_table.created_at', 'DESC');
        if ($limit !== null) {
            $collection->setPageSize($limit);
        }

        return ($this->describe)($collection);
    }

    public function inOrdersOf(DocumentList $related, bool $exclude = false): self
    {
        $condition = $exclude ? 'nin' : 'in';
        $collection = clone $this->collection;
        $collection->addFieldToFilter($this->orderIdField, [$condition => $related->orderIds()]);

        return new self($collection, $this->describe, $this->orderIdField);
    }

    private function orderIds(): Select
    {
        $select = clone $this->collection->getSelect();
        $select->reset(Select::COLUMNS);
        $select->columns($this->orderIdField);

        return $select;
    }
}

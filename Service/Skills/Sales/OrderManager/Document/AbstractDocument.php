<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Sales\OrderManager\Document;

use Magento\Framework\Data\Collection\AbstractDb;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

abstract class AbstractDocument implements DocumentTypeInterface
{
    public const FIELD_CLASSIFICATION = [
        'order_number' => [PiiClass::TOKENISE, 'order'],
        'order_id' => [PiiClass::TOKENISE, 'order'],
        'number' => [PiiClass::TOKENISE, 'document'],
        'date' => [PiiClass::PUBLIC],
        'status' => [PiiClass::PUBLIC],
        'state' => [PiiClass::PUBLIC],
        'customer' => [PiiClass::TOKENISE, 'name'],
        'email' => [PiiClass::TOKENISE, 'email'],
        'vat_id' => [PiiClass::TOKENISE, 'vat'],
        'total' => [PiiClass::PUBLIC],
        'currency' => [PiiClass::PUBLIC],
        'qty' => [PiiClass::PUBLIC],
        'tracking' => [PiiClass::TOKENISE, 'tracking'],
        'carrier' => [PiiClass::PUBLIC],
        'admin_url' => [PiiClass::TOKENISE, 'url'],
    ];

    public const SORTS = [
        'newest' => ['created_at', 'DESC'],
        'oldest' => ['created_at', 'ASC'],
        'highest_total' => ['grand_total', 'DESC'],
        'lowest_total' => ['grand_total', 'ASC'],
    ];

    protected const FIELD_FILTERS = [
        'document_number' => ['increment_id', 'eq'],
        'from' => ['created_at', 'from'],
        'to' => ['created_at', 'to'],
        'min_total' => ['grand_total', 'from'],
        'max_total' => ['grand_total', 'to'],
        'order_ids' => ['order_id', 'in'],
    ];

    public function list(array $filters): DocumentList
    {
        $orderIdColumn = static::FIELD_FILTERS['order_ids'][0];

        return new DocumentList(
            $this->collection($filters),
            $this->describe(...),
            'main_table.' . $orderIdColumn,
            static::SORTS
        );
    }

    abstract protected function collection(array $filters): AbstractDb;

    abstract protected function describe(AbstractDb $collection): array;

    protected function filterFields(AbstractDb $collection, array $filters): void
    {
        foreach (static::FIELD_FILTERS as $name => [$column, $condition]) {
            $value = $filters[$name] ?? null;
            if (!is_array($value) && !$value) {
                continue;
            }

            $collection->addFieldToFilter('main_table.' . $column, [$condition => $value]);
        }
    }
}

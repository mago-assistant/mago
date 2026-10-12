<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Sales\OrderManager;

use Magento\Framework\AuthorizationInterface;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\Document\AbstractDocument;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\Document\DocumentTypeInterface;
use MagoAssistant\Mago\Service\Skills\PeriodParser;

class ListDocumentsAction implements ActionInterface
{
    private const SEARCH_DEFAULT_PERIOD = '30days';

    private const ORDER_FIELDS = ['order_number', 'customer'];

    private const FILTER_OWNERS = [
        'order_number' => ['order', 'document_number'],
        'customer' => ['order', 'customer'],
        'carrier' => ['shipment', 'carrier'],
        'tracking_number' => ['shipment', 'tracking_number'],
        'payment_method' => ['order', 'payment_method'],
    ];

    private const TOTAL_FILTERS = ['min_total', 'max_total'];

    private const TOTAL_OWNERS = [
        'shipment' => 'order',
    ];

    private const SORT_OWNERS = [
        'shipment' => [
            'highest_total' => 'order',
            'lowest_total' => 'order',
        ],
    ];

    public function __construct(
        private readonly PeriodParser $periodParser,
        private readonly AuthorizationInterface $authorization,
        private readonly array $documentTypes = []
    ) {
    }

    public function getName(): string
    {
        return 'list_documents';
    }

    public function getDescription(): string
    {
        return 'List orders, invoices, shipments or credit memos';
    }

    public function getParameterSchema(): array
    {
        return [
            'document_type' => [
                'type' => 'string',
                'enum' => array_keys($this->documentTypes),
                'description' => 'What kind of document to get or list',
            ],
            'document_number' => [
                'type' => 'string',
                'description' => 'The document\'s own number (e.g. "000000549")',
            ],
            'order_number' => [
                'type' => 'string',
                'description' => 'Order increment ID (e.g. "000000549"). With list_documents: the order '
                    . 'the listed documents belong to',
            ],
            'customer' => [
                'type' => 'string',
                'description' => 'Customer name or email address (partial match, e.g. "Vries" or "@gmail.com")',
            ],
            'product' => [
                'type' => 'string',
                'description' => 'Product name or SKU in the document\'s lines '
                    . '(partial match, e.g. "Nevada" or "89807")',
            ],
            'document_status' => [
                'type' => 'string',
                'description' => 'Only when the user asks for this status of the listed documents. '
                    . 'Orders: an order status (e.g. "processing"). '
                    . 'Invoices: "open", "paid" or "canceled". Credit memos: "open", "refunded" or "canceled"',
            ],
            'carrier' => [
                'type' => 'string',
                'description' => 'Shipping carrier of a shipment, as the user named it (partial match, '
                    . 'e.g. "DHL Parcel")',
            ],
            'tracking_number' => [
                'type' => 'string',
                'description' => 'Tracking number of a shipment (e.g. "3SABCD1234567")',
            ],
            'min_total' => [
                'type' => 'number',
                'description' => 'Grand total of at least this amount (for shipments: of their order)',
            ],
            'max_total' => [
                'type' => 'number',
                'description' => 'Grand total of at most this amount (for shipments: of their order)',
            ],
            'payment_method' => [
                'type' => 'string',
                'description' => 'Payment method of an order (partial match, e.g. "PayPal")',
            ],
            'period' => [
                'type' => 'string',
                'description' => 'When the document was created: "today", "yesterday", "7days", "30days", '
                    . '"this_month", "last_month", "this_year", "YYYY-MM", or '
                    . '"YYYY-MM-DD:YYYY-MM-DD" for a custom range, or "all". Leave it out to list all; '
                    . 'unsorted customer or product searches cover the last 30 days',
            ],
            'with' => [
                'type' => 'array',
                'description' => 'Other documents of the same order that must match too. Each is an object with '
                    . 'a document_type and any of the filters above, which apply to that document, period '
                    . 'included. E.g. [{"document_type": "credit_memo", "document_status": "refunded"}] for '
                    . 'orders with a refunded credit memo, or [{"document_type": "order", "period": "2026-05"}] '
                    . 'for invoices of orders placed in May 2026',
                'items' => ['type' => 'object'],
            ],
            'without' => [
                'type' => 'array',
                'description' => 'Other documents of the same order that must not exist, in the same format as '
                    . 'with. E.g. [{"document_type": "shipment"}] for orders that have not been shipped yet',
                'items' => ['type' => 'object'],
            ],
            'include' => [
                'type' => 'array',
                'description' => 'Other documents of the same order to add to each listed document, e.g. '
                    . '["invoice", "shipment"] to see whether an order is invoiced and shipped',
                'items' => [
                    'type' => 'string',
                    'enum' => array_keys($this->documentTypes),
                ],
            ],
            'sort' => [
                'type' => 'string',
                'enum' => array_keys(AbstractDocument::SORTS),
                'description' => 'Order of the documents (default: newest), e.g. highest_total with limit 1 for '
                    . 'the biggest one',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Number of documents to return (default: 10, max: 50)',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return null;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function getFieldClassification(): array
    {
        return [
            'total_count' => [PiiClass::PUBLIC],
            'period' => [PiiClass::PUBLIC],
        ] + AbstractDocument::FIELD_CLASSIFICATION;
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $params = $this->routeSort($params);
        $limit = (int)($params['limit'] ?? 10);
        $limit = max($limit, 1);
        $limit = min($limit, 50);

        $query = $this->query($params);
        if (isset($query['error'])) {
            return $query;
        }

        $filters = $query['filters'];
        $documentList = $query['document_list'];
        foreach ($params['include'] ?? [] as $includedName) {
            $includedType = $this->documentType($includedName);
            if (is_array($includedType)) {
                return $includedType;
            }

            $documentList = $documentList->include($includedName, $includedType);
        }

        $sort = $params['sort'] ?? 'newest';
        $documentList = $documentList->sortedBy($sort);

        $result = [
            'total_count' => $documentList->size(),
            'documents' => $documentList->documents($limit),
        ];
        if (isset($filters['period'])) {
            $result['period'] = $filters['period'];
        }

        $typeName = $params['document_type'] ?? 'order';
        if ($typeName === 'order') {
            return $result;
        }

        return $this->withOrderFields($result);
    }

    private function query(array $params): array
    {
        $defaultPeriod = $this->defaultPeriod($params);
        $params = $this->routeTotals($this->routeFilters($params));

        $typeName = $params['document_type'] ?? 'order';
        $documentType = $this->documentType($typeName);
        if (is_array($documentType)) {
            return $documentType;
        }

        $filters = $this->filters($params, $defaultPeriod);
        if (isset($filters['error'])) {
            return $filters;
        }

        try {
            $documentList = $documentType->list($filters);
        } catch (\InvalidArgumentException $exception) {
            return ['error' => $exception->getMessage()];
        }
        foreach (['with' => false, 'without' => true] as $key => $exclude) {
            foreach ($params[$key] ?? [] as $relatedParams) {
                $relatedQuery = $this->query($relatedParams + ['period' => 'all']);
                if (isset($relatedQuery['error'])) {
                    return $relatedQuery;
                }

                $documentList = $documentList->inOrdersOf($relatedQuery['document_list'], $exclude);
            }
        }

        return [
            'document_list' => $documentList,
            'filters' => $filters,
        ];
    }

    private function documentType(string $typeName): DocumentTypeInterface|array
    {
        $documentType = $this->documentTypes[$typeName] ?? null;
        if ($documentType === null) {
            return ['error' => 'Unknown document_type: ' . $typeName];
        }

        if (!$this->authorization->isAllowed($documentType->getAclResource())) {
            return ['error' => 'You do not have permission to access ' . $typeName . ' documents'];
        }

        return $documentType;
    }

    private function routeSort(array $params): array
    {
        $typeName = $params['document_type'] ?? 'order';
        $sort = $params['sort'] ?? 'newest';
        $ownerType = self::SORT_OWNERS[$typeName][$sort] ?? null;
        if ($ownerType === null) {
            return $params;
        }

        $relatedParams = array_diff_key($params, array_flip(['sort', 'limit', 'include']));
        $includes = $params['include'] ?? [];

        return [
            'document_type' => $ownerType,
            'sort' => $sort,
            'limit' => $params['limit'] ?? 10,
            'with' => [$relatedParams],
            'include' => [$typeName, ...$includes],
        ];
    }

    private function routeFilters(array $params): array
    {
        $typeName = $params['document_type'] ?? 'order';

        foreach (self::FILTER_OWNERS as $name => [$ownerType, $filter]) {
            $value = $params[$name] ?? '';
            $isOwnFilter = $ownerType === $typeName && $name === $filter;
            if ($value === '' || $isOwnFilter) {
                continue;
            }

            unset($params[$name]);

            if ($ownerType === $typeName) {
                $params[$filter] = $value;
                continue;
            }

            $params['with'][] = [
                'document_type' => $ownerType,
                $filter => $value,
            ];
        }

        return $params;
    }

    private function routeTotals(array $params): array
    {
        $ownerType = self::TOTAL_OWNERS[$params['document_type'] ?? 'order'] ?? null;
        $totals = array_filter(
            array_intersect_key($params, array_flip(self::TOTAL_FILTERS)),
            fn(mixed $total): bool => $total !== '' && $total !== null
        );
        if ($ownerType === null || $totals === []) {
            return $params;
        }

        $params = array_diff_key($params, $totals);
        $params['with'][] = ['document_type' => $ownerType] + $totals;

        return $params;
    }

    private function defaultPeriod(array $params): string
    {
        $isSearch = ($params['customer'] ?? '') !== '' || ($params['product'] ?? '') !== '';
        $isSorted = isset($params['sort']);
        if (!$isSearch || $isSorted) {
            return '';
        }

        return self::SEARCH_DEFAULT_PERIOD;
    }

    private function filters(array $params, string $defaultPeriod): array
    {
        $filters = [
            'document_number' => $params['document_number'] ?? '',
            'document_status' => $params['document_status'] ?? '',
            'customer' => $params['customer'] ?? '',
            'product' => $params['product'] ?? '',
            'carrier' => $params['carrier'] ?? '',
            'tracking_number' => $params['tracking_number'] ?? '',
            'payment_method' => $params['payment_method'] ?? '',
            'min_total' => $params['min_total'] ?? '',
            'max_total' => $params['max_total'] ?? '',
        ];

        $period = $params['period'] ?? '';
        if ($period === '') {
            $period = $defaultPeriod;
        }

        if ($period === '') {
            return $filters;
        }

        try {
            [$from, $to] = $this->periodParser->parse($period);
        } catch (\InvalidArgumentException $exception) {
            return ['error' => $exception->getMessage()];
        }

        $filters['period'] = $period;
        $filters['from'] = $from;
        $filters['to'] = $to;

        return $filters;
    }

    private function withOrderFields(array $result): array
    {
        $documents = $result['documents'] ?? [];
        if ($documents === []) {
            return $result;
        }

        $orderIds = array_column($documents, 'order_id');
        $orderDocument = $this->documentTypes['order'];
        $orderList = $orderDocument->list(['order_ids' => $orderIds]);

        $orders = [];
        foreach ($orderList->documents() as $order) {
            $orders[$order['order_id']] = $order;
        }

        foreach ($documents as $index => $document) {
            $order = $orders[$document['order_id']] ?? [];
            foreach (self::ORDER_FIELDS as $field) {
                $documents[$index][$field] = $order[$field] ?? '';
            }
        }

        $result['documents'] = $documents;
        return $result;
    }
}

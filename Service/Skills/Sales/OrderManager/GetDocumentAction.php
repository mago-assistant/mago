<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Sales\OrderManager;

use Magento\Framework\AuthorizationInterface;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\Document\AbstractDocument;

class GetDocumentAction implements ActionInterface
{
    public function __construct(
        private readonly AuthorizationInterface $authorization,
        private readonly array $documentTypes = []
    ) {
    }

    public function getName(): string
    {
        return 'get_document';
    }

    public function getDescription(): string
    {
        return 'Get one order, invoice, shipment or credit memo by its own number. For the invoices, '
            . 'shipments or credit memos of an order, use list_documents with order_number';
    }

    public function getParameterSchema(): array
    {
        return [
            'document_type' => [
                'type' => 'string',
                'enum' => array_keys($this->documentTypes),
                'description' => 'What kind of document to get or list',
            ],
            'reference' => [
                'type' => 'string',
                'description' => 'Number of the document, as the user gave it (e.g. "000000549")',
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
        return AbstractDocument::FIELD_CLASSIFICATION;
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $typeName = $params['document_type'] ?? 'order';
        $documentType = $this->documentTypes[$typeName] ?? null;
        if ($documentType === null) {
            return ['error' => 'Unknown document_type: ' . $typeName];
        }

        if (!$this->authorization->isAllowed($documentType->getAclResource())) {
            return ['error' => 'You do not have permission to access ' . $typeName . ' documents'];
        }

        $reference = $params['reference'] ?? '';
        if ($reference === '') {
            return ['error' => 'reference is required for get_document'];
        }

        $documents = $documentType->list(['document_number' => $reference])->documents(1);
        if ($documents === []) {
            return ['error' => 'No ' . str_replace('_', ' ', $typeName) . ' has number ' . $reference];
        }

        return $documents[0];
    }
}

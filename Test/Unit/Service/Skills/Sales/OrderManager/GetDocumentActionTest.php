<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Sales\OrderManager;

use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\Document\DocumentTypeInterface;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\GetDocumentAction;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAclAuthorization;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class GetDocumentActionTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    /**
     * @param string[] $allowedResources
     */
    private function action(array $allowedResources = ['Magento_Sales::sales_invoice']): GetDocumentAction
    {
        $invoiceType = $this->createStub(DocumentTypeInterface::class);
        $invoiceType->method('getAclResource')->willReturn('Magento_Sales::sales_invoice');

        return new GetDocumentAction(
            new FakeAclAuthorization($allowedResources),
            ['invoice' => $invoiceType]
        );
    }

    #[Test]
    public function itRejectsAnUnknownDocumentType(): void
    {
        $params = [
            'document_type' => 'parcel',
            'document_number' => '000000045',
        ];

        $result = $this->action()->execute($params, self::ADMIN_USER_ID);
        self::assertSame('Unknown document_type: parcel', $result['error']);
    }

    #[Test]
    public function itRequiresADocumentNumber(): void
    {
        $result = $this->action()->execute(['document_type' => 'invoice'], self::ADMIN_USER_ID);
        self::assertSame('document_number is required for get_document', $result['error']);
    }

    #[Test]
    public function itRefusesADocumentTypeTheAdminMayNotAccess(): void
    {
        $action = $this->action([]);
        $params = [
            'document_type' => 'invoice',
            'document_number' => '000000045',
        ];

        $result = $action->execute($params, self::ADMIN_USER_ID);
        self::assertSame('You do not have permission to access invoice documents', $result['error']);
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Sales\OrderManager;

use Magento\Framework\Data\Collection\AbstractDb;
use MagoAssistant\Mago\Service\Skills\PeriodParser;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\Document\DocumentList;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\Document\DocumentTypeInterface;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\ListDocumentsAction;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAclAuthorization;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ListDocumentsActionTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    private const ACL_RESOURCES = [
        'order' => 'Magento_Sales::sales_order',
        'invoice' => 'Magento_Sales::sales_invoice',
        'shipment' => 'Magento_Sales::shipment',
        'credit_memo' => 'Magento_Sales::sales_creditmemo',
    ];

    /**
     * @param string[] $allowedResources
     */
    private function action(array $allowedResources = self::ACL_RESOURCES): ListDocumentsAction
    {
        $documentTypes = array_map(
            fn(string $aclResource) => $this->documentType($aclResource),
            self::ACL_RESOURCES
        );

        return new ListDocumentsAction(
            new PeriodParser(),
            new FakeAclAuthorization($allowedResources),
            $documentTypes
        );
    }

    private function documentType(string $aclResource): DocumentTypeInterface
    {
        $documentList = new DocumentList(
            $this->createStub(AbstractDb::class),
            fn() => []
        );

        $documentType = $this->createStub(DocumentTypeInterface::class);
        $documentType->method('getAclResource')->willReturn($aclResource);
        $documentType->method('list')->willReturn($documentList);

        return $documentType;
    }

    #[Test]
    public function itRejectsAnUnknownDocumentType(): void
    {
        $result = $this->action()->execute(['document_type' => 'parcel'], self::ADMIN_USER_ID);
        self::assertSame('Unknown document_type: parcel', $result['error']);
    }

    #[Test]
    public function itRejectsAnUnknownDocumentTypeInWith(): void
    {
        $params = [
            'document_type' => 'order',
            'with' => [['document_type' => 'parcel']],
        ];

        $result = $this->action()->execute($params, self::ADMIN_USER_ID);
        self::assertSame('Unknown document_type: parcel', $result['error']);
    }

    #[Test]
    public function itRefusesADocumentTypeTheAdminMayNotAccess(): void
    {
        $action = $this->action(['Magento_Sales::sales_order']);

        $result = $action->execute(['document_type' => 'invoice'], self::ADMIN_USER_ID);
        self::assertSame('You do not have permission to access invoice documents', $result['error']);
    }

    #[Test]
    public function itRefusesARelatedDocumentTypeTheAdminMayNotAccess(): void
    {
        $action = $this->action(['Magento_Sales::sales_order']);
        $params = [
            'document_type' => 'order',
            'with' => [['document_type' => 'credit_memo']],
        ];

        $result = $action->execute($params, self::ADMIN_USER_ID);
        self::assertSame('You do not have permission to access credit_memo documents', $result['error']);
    }

    #[Test]
    public function itRefusesAnExcludedDocumentTypeTheAdminMayNotAccess(): void
    {
        $action = $this->action(['Magento_Sales::sales_order']);
        $params = [
            'document_type' => 'order',
            'without' => [['document_type' => 'shipment']],
        ];

        $result = $action->execute($params, self::ADMIN_USER_ID);
        self::assertSame('You do not have permission to access shipment documents', $result['error']);
    }

    #[Test]
    public function itRefusesAnOrderListByTrackingNumberWithoutShipmentAccess(): void
    {
        $action = $this->action(['Magento_Sales::sales_order']);
        $params = [
            'document_type' => 'order',
            'tracking_number' => 'TRACK123',
        ];

        $result = $action->execute($params, self::ADMIN_USER_ID);
        self::assertSame('You do not have permission to access shipment documents', $result['error']);
    }

    #[Test]
    public function itRejectsAnUnrecognisedPeriod(): void
    {
        $params = [
            'document_type' => 'order',
            'period' => 'last summer',
        ];

        $result = $this->action()->execute($params, self::ADMIN_USER_ID);
        self::assertStringStartsWith('Unrecognized period "last summer"', $result['error']);
    }
}

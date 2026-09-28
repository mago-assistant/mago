<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Sales\OrderManager;

use Magento\Framework\DB\Select;
use MagoAssistant\Mago\Service\Skills\PeriodParser;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\ListDocumentsAction;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAclAuthorization;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ListDocumentsActionTest extends TestCase
{
    private const ADMIN_USER_ID = 7;

    private const ACL_RESOURCES = [
        'order' => 'Magento_Sales::actions_view',
        'invoice' => 'Magento_Sales::sales_invoice',
        'shipment' => 'Magento_Sales::shipment',
        'credit_memo' => 'Magento_Sales::sales_creditmemo',
    ];

    /**
     * @var RecordingDocumentType[]
     */
    private array $documentTypes;

    protected function setUp(): void
    {
        $select = $this->createStub(Select::class);
        $this->documentTypes = array_map(
            fn(string $aclResource): RecordingDocumentType => new RecordingDocumentType($aclResource, $select),
            self::ACL_RESOURCES
        );
    }

    /**
     * @param string[] $allowedResources
     */
    private function action(array $allowedResources = self::ACL_RESOURCES): ListDocumentsAction
    {
        return new ListDocumentsAction(
            new PeriodParser(),
            new FakeAclAuthorization($allowedResources),
            $this->documentTypes
        );
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
        $action = $this->action(['Magento_Sales::actions_view']);

        $result = $action->execute(['document_type' => 'invoice'], self::ADMIN_USER_ID);
        self::assertSame('You do not have permission to access invoice documents', $result['error']);
    }

    #[Test]
    public function itRefusesARelatedDocumentTypeTheAdminMayNotAccess(): void
    {
        $action = $this->action(['Magento_Sales::actions_view']);
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
        $action = $this->action(['Magento_Sales::actions_view']);
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
        $action = $this->action(['Magento_Sales::actions_view']);
        $params = [
            'document_type' => 'order',
            'tracking_number' => 'TRACK123',
        ];

        $result = $action->execute($params, self::ADMIN_USER_ID);
        self::assertSame('You do not have permission to access shipment documents', $result['error']);
    }

    #[Test]
    public function itRefusesAnIncludedDocumentTypeTheAdminMayNotAccess(): void
    {
        $action = $this->action(['Magento_Sales::actions_view']);
        $params = [
            'document_type' => 'order',
            'include' => ['invoice'],
        ];

        $result = $action->execute($params, self::ADMIN_USER_ID);
        self::assertSame('You do not have permission to access invoice documents', $result['error']);
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

    #[Test]
    public function itDeclaresTheOrderNumberFilter(): void
    {
        $schema = $this->action()->getParameterSchema();

        self::assertArrayHasKey('order_number', $schema);
    }

    #[Test]
    public function itSearchesTheLastThirtyDaysForACustomerWithoutAPeriod(): void
    {
        $params = [
            'document_type' => 'order',
            'customer' => 'Vries',
        ];

        $result = $this->action()->execute($params, self::ADMIN_USER_ID);

        self::assertSame('30days', $result['period']);
    }

    #[Test]
    public function itSearchesTheLastThirtyDaysForAProductWithoutAPeriod(): void
    {
        $params = [
            'document_type' => 'order',
            'product' => 'Velora',
        ];

        $result = $this->action()->execute($params, self::ADMIN_USER_ID);

        self::assertSame('30days', $result['period']);
    }

    #[Test]
    public function itSearchesAllHistoryForASortedCustomerSearch(): void
    {
        $params = [
            'document_type' => 'order',
            'customer' => 'Vries',
            'sort' => 'newest',
        ];

        $result = $this->action()->execute($params, self::ADMIN_USER_ID);

        self::assertArrayNotHasKey('period', $result);
    }

    #[Test]
    public function itKeepsThePeriodTheModelAskedForInACustomerSearch(): void
    {
        $params = [
            'document_type' => 'order',
            'customer' => 'Vries',
            'period' => 'this_year',
        ];

        $result = $this->action()->execute($params, self::ADMIN_USER_ID);

        self::assertSame('this_year', $result['period']);
    }

    #[Test]
    public function itListsAllHistoryWhenNothingIsSearched(): void
    {
        $result = $this->action()->execute(['document_type' => 'order'], self::ADMIN_USER_ID);

        self::assertArrayNotHasKey('period', $result);
    }

    #[Test]
    public function itSearchesTheInvoicesOfACustomerInTheLastThirtyDays(): void
    {
        $params = [
            'document_type' => 'invoice',
            'customer' => 'Vries',
        ];

        $result = $this->action()->execute($params, self::ADMIN_USER_ID);

        self::assertSame('30days', $result['period']);
        $orderFilters = $this->documentTypes['order']->recordedFilters()[0];
        self::assertSame('Vries', $orderFilters['customer']);
        self::assertSame('all', $orderFilters['period']);
    }

    #[Test]
    public function itFiltersShipmentsByTheTotalOfTheirOrder(): void
    {
        $params = [
            'document_type' => 'shipment',
            'min_total' => 500,
            'max_total' => 900,
        ];

        $this->action()->execute($params, self::ADMIN_USER_ID);

        $shipmentFilters = $this->documentTypes['shipment']->recordedFilters()[0];
        $orderFilters = $this->documentTypes['order']->recordedFilters()[0];
        self::assertSame('', $shipmentFilters['min_total']);
        self::assertSame('', $shipmentFilters['max_total']);
        self::assertSame(500, $orderFilters['min_total']);
        self::assertSame(900, $orderFilters['max_total']);
    }

    #[Test]
    public function itFiltersInvoicesByTheirOwnTotal(): void
    {
        $params = [
            'document_type' => 'invoice',
            'min_total' => 500,
        ];

        $this->action()->execute($params, self::ADMIN_USER_ID);

        self::assertSame(500, $this->documentTypes['invoice']->recordedFilters()[0]['min_total']);
        self::assertSame([], $this->documentTypes['order']->recordedFilters());
    }

    #[Test]
    public function itReportsAFilterTheDocumentTypeRejects(): void
    {
        $this->documentTypes['invoice']->givenListFails('Unknown invoice status "pending"');

        $result = $this->action()->execute(
            ['document_type' => 'invoice', 'document_status' => 'pending'],
            self::ADMIN_USER_ID
        );

        self::assertSame('Unknown invoice status "pending"', $result['error']);
    }
}

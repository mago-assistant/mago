<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Integration\Service\Skills\Sales\OrderManager;

use Magento\Framework\App\ResourceConnection;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\ListDocumentsAction;
use MagoAssistant\Mago\Test\Integration\Fakes\FakeAuthorization;
use MagoAssistant\Mago\Test\Integration\MagentoObjectManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ListDocumentsActionTest extends TestCase
{
    private const PERIOD = '2001-02-10';
    private const CREATED_AT = '2001-02-10 12:00:00';
    private const ADMIN_USER_ID = 1;

    private SalesDocumentFixture $fixture;
    private ListDocumentsAction $action;

    protected function setUp(): void
    {
        $objectManager = MagentoObjectManager::get();
        $authorization = new FakeAuthorization([
            'Magento_Sales::actions_view' => true,
            'Magento_Sales::sales_invoice' => true,
            'Magento_Sales::shipment' => true,
            'Magento_Sales::sales_creditmemo' => true,
        ]);

        $this->fixture = new SalesDocumentFixture($objectManager->get(ResourceConnection::class), self::CREATED_AT);
        $this->action = $objectManager->create(ListDocumentsAction::class, ['authorization' => $authorization]);
        $this->fixture->begin();
    }

    protected function tearDown(): void
    {
        $this->fixture->rollBack();
    }

    #[Test]
    public function itCountsEveryMatchingDocumentBeyondTheLimit(): void
    {
        $this->fixture->order('IT-1001');
        $this->fixture->order('IT-1002');
        $this->fixture->order('IT-1003');

        $result = $this->listDocuments(['document_type' => 'order', 'limit' => 2]);
        self::assertSame(3, $result['total_count']);
        self::assertCount(2, $result['documents']);
    }

    #[Test]
    public function itFindsAnOrderByItsNumber(): void
    {
        $this->fixture->order('IT-1001');
        $this->fixture->order('IT-1002');

        $result = $this->listDocuments(['document_type' => 'order', 'document_number' => 'IT-1002']);
        self::assertSame(['IT-1002'], $this->numbers($result));
    }

    #[Test]
    public function itFiltersOrdersByStatus(): void
    {
        $this->fixture->order('IT-1001', ['status' => 'processing']);
        $this->fixture->order('IT-1002', ['status' => 'complete']);

        $result = $this->listDocuments(['document_type' => 'order', 'document_status' => 'complete']);
        self::assertSame(['IT-1002'], $this->numbers($result));
    }

    #[Test]
    public function itFindsOrdersByCustomerEmailOrLastName(): void
    {
        $this->fixture->order('IT-1001', ['email' => 'anna.jansen@example.com', 'lastname' => 'Jansen']);
        $this->fixture->order('IT-1002', ['email' => 'piet.bakker@example.com', 'lastname' => 'Bakker']);

        $byEmail = $this->listDocuments(['document_type' => 'order', 'customer' => 'piet.bakker@example.com']);
        $byName = $this->listDocuments(['document_type' => 'order', 'customer' => 'Anna Jansen']);
        self::assertSame(['IT-1002'], $this->numbers($byEmail));
        self::assertSame(['IT-1001'], $this->numbers($byName));
    }

    #[Test]
    public function itFindsAGuestOrderByTheNameOnItsBillingAddress(): void
    {
        $this->fixture->order('IT-1001', [
            'firstname' => '',
            'lastname' => '',
            'billing_firstname' => 'Kees',
            'billing_lastname' => 'Visser',
        ]);
        $this->fixture->order('IT-1002');

        $result = $this->listDocuments(['document_type' => 'order', 'customer' => 'Kees Visser']);
        self::assertSame(['IT-1001'], $this->numbers($result));
        self::assertSame('Kees Visser', $result['documents'][0]['customer']);
    }

    #[Test]
    public function itFindsOrdersByAFirstNameAlone(): void
    {
        $this->fixture->order('IT-1001', ['firstname' => 'Kees', 'lastname' => 'Visser']);
        $this->fixture->order('IT-1002', ['firstname' => 'Anna', 'lastname' => 'Jansen']);

        $result = $this->listDocuments(['document_type' => 'order', 'customer' => 'Kees']);
        self::assertSame(['IT-1001'], $this->numbers($result));
    }

    #[Test]
    public function itFindsOrdersByProductSkuOrName(): void
    {
        $this->fixture->order('IT-1001', ['sku' => 'FJORD-300', 'product' => 'Fjordrunner 300']);
        $this->fixture->order('IT-1002', ['sku' => 'VELO-2', 'product' => 'Velora X2']);

        $bySku = $this->listDocuments(['document_type' => 'order', 'product' => 'VELO']);
        $byName = $this->listDocuments(['document_type' => 'order', 'product' => 'Fjordrunner']);
        self::assertSame(['IT-1002'], $this->numbers($bySku));
        self::assertSame(['IT-1001'], $this->numbers($byName));
    }

    #[Test]
    public function itFindsOrdersByPaymentMethod(): void
    {
        $this->fixture->order('IT-1001', ['payment' => 'payquick_card']);
        $this->fixture->order('IT-1002', ['payment' => 'banktransfer']);

        $result = $this->listDocuments(['document_type' => 'order', 'payment_method' => 'PayQuick']);
        self::assertSame(['IT-1001'], $this->numbers($result));
    }

    #[Test]
    public function itFiltersOrdersByGrandTotal(): void
    {
        $this->fixture->order('IT-1001', ['total' => 50.00]);
        $this->fixture->order('IT-1002', ['total' => 150.00]);
        $this->fixture->order('IT-1003', ['total' => 600.00]);

        $result = $this->listDocuments(['document_type' => 'order', 'min_total' => 100, 'max_total' => 500]);
        self::assertSame(['IT-1002'], $this->numbers($result));
    }

    #[Test]
    public function itFiltersInvoicesByState(): void
    {
        $orderId = $this->fixture->order('IT-1001');
        $this->fixture->invoice($orderId, 'IT-2001', 1);
        $this->fixture->invoice($orderId, 'IT-2002', 2);

        $result = $this->listDocuments(['document_type' => 'invoice', 'document_status' => 'open']);
        self::assertSame(['IT-2001'], $this->numbers($result));
    }

    #[Test]
    public function itFindsShipmentsByCarrier(): void
    {
        $firstOrderId = $this->fixture->order('IT-1001');
        $secondOrderId = $this->fixture->order('IT-1002');
        $this->fixture->shipment($firstOrderId, 'IT-3001', 'NordFreight', 'NF123');
        $this->fixture->shipment($secondOrderId, 'IT-3002', 'SwiftParcel', 'SP456');

        $result = $this->listDocuments(['document_type' => 'shipment', 'carrier' => 'SwiftParcel']);
        self::assertSame(['IT-3002'], $this->numbers($result));
    }

    #[Test]
    public function itFindsTheOrderOfATrackingNumber(): void
    {
        $firstOrderId = $this->fixture->order('IT-1001');
        $secondOrderId = $this->fixture->order('IT-1002');
        $this->fixture->shipment($firstOrderId, 'IT-3001', 'NordFreight', 'NF123');
        $this->fixture->shipment($secondOrderId, 'IT-3002', 'NordFreight', 'NF456');

        $result = $this->listDocuments(['document_type' => 'order', 'tracking_number' => 'NF456']);
        self::assertSame(['IT-1002'], $this->numbers($result));
    }

    #[Test]
    public function itListsTheInvoicesOfAnOrderNumber(): void
    {
        $firstOrderId = $this->fixture->order('IT-1001');
        $secondOrderId = $this->fixture->order('IT-1002');
        $this->fixture->invoice($firstOrderId, 'IT-2001');
        $this->fixture->invoice($secondOrderId, 'IT-2002');

        $result = $this->listDocuments(['document_type' => 'invoice', 'order_number' => 'IT-1002']);
        self::assertSame(['IT-2002'], $this->numbers($result));
    }

    #[Test]
    public function itListsTheInvoicesOfACustomer(): void
    {
        $firstOrderId = $this->fixture->order('IT-1001', ['email' => 'kees.visser@example.com']);
        $secondOrderId = $this->fixture->order('IT-1002', ['email' => 'anna.jansen@example.com']);
        $this->fixture->invoice($firstOrderId, 'IT-2001');
        $this->fixture->invoice($secondOrderId, 'IT-2002');

        $result = $this->listDocuments(['document_type' => 'invoice', 'customer' => 'kees.visser@example.com']);
        self::assertSame(['IT-2001'], $this->numbers($result));
    }

    #[Test]
    public function itListsTheInvoicesOfOrdersWithAProduct(): void
    {
        $firstOrderId = $this->fixture->order('IT-1001', ['sku' => 'VELO-2', 'product' => 'Velora X2']);
        $secondOrderId = $this->fixture->order('IT-1002', ['sku' => 'FJORD-300', 'product' => 'Fjordrunner 300']);
        $this->fixture->invoice($firstOrderId, 'IT-2001');
        $this->fixture->invoice($secondOrderId, 'IT-2002');

        $result = $this->listDocuments([
            'document_type' => 'invoice',
            'with' => [['document_type' => 'order', 'product' => 'Velora']],
        ]);
        self::assertSame(['IT-2001'], $this->numbers($result));
    }

    #[Test]
    public function itListsTheInvoicesOfOrdersFromOtherCustomers(): void
    {
        $firstOrderId = $this->fixture->order('IT-1001', ['email' => 'kees.visser@example.com']);
        $secondOrderId = $this->fixture->order('IT-1002', ['email' => 'anna.jansen@example.com']);
        $this->fixture->invoice($firstOrderId, 'IT-2001');
        $this->fixture->invoice($secondOrderId, 'IT-2002');

        $result = $this->listDocuments([
            'document_type' => 'invoice',
            'without' => [['document_type' => 'order', 'customer' => 'kees.visser@example.com']],
        ]);
        self::assertSame(['IT-2002'], $this->numbers($result));
    }

    #[Test]
    public function itListsTheOrdersWithARefundedCreditMemo(): void
    {
        $refundedOrderId = $this->fixture->order('IT-1001');
        $openOrderId = $this->fixture->order('IT-1002');
        $this->fixture->order('IT-1003');
        $this->fixture->creditmemo($refundedOrderId, 'IT-4001', 2);
        $this->fixture->creditmemo($openOrderId, 'IT-4002', 1);

        $result = $this->listDocuments([
            'document_type' => 'order',
            'with' => [['document_type' => 'credit_memo', 'document_status' => 'refunded']],
        ]);
        self::assertSame(['IT-1001'], $this->numbers($result));
    }

    #[Test]
    public function itListsTheOrdersThatHaveNotBeenShipped(): void
    {
        $shippedOrderId = $this->fixture->order('IT-1001');
        $this->fixture->order('IT-1002');
        $this->fixture->shipment($shippedOrderId, 'IT-3001');

        $result = $this->listDocuments([
            'document_type' => 'order',
            'without' => [['document_type' => 'shipment']],
        ]);
        self::assertSame(['IT-1002'], $this->numbers($result));
    }

    #[Test]
    public function itAddsTheOrderNumberAndCustomerToInvoices(): void
    {
        $orderId = $this->fixture->order('IT-1001', ['firstname' => 'Anna', 'lastname' => 'Jansen']);
        $this->fixture->invoice($orderId, 'IT-2001');

        $result = $this->listDocuments(['document_type' => 'invoice']);
        self::assertSame('IT-1001', $result['documents'][0]['order_number']);
        self::assertSame('Anna Jansen', $result['documents'][0]['customer']);
    }

    #[Test]
    public function itIncludesTheInvoicesAndShipmentsOfEachOrder(): void
    {
        $shippedOrderId = $this->fixture->order('IT-1001');
        $this->fixture->order('IT-1002');
        $this->fixture->invoice($shippedOrderId, 'IT-2001');
        $this->fixture->shipment($shippedOrderId, 'IT-3001', 'NordFreight', 'NF123');

        $result = $this->listDocuments([
            'document_type' => 'order',
            'include' => ['invoice', 'shipment'],
        ]);
        $orders = array_column($result['documents'], null, 'order_number');
        self::assertSame(['IT-2001'], array_column($orders['IT-1001']['invoice'], 'number'));
        self::assertSame(['NF123'], $orders['IT-1001']['shipment'][0]['tracking']);
        self::assertSame([], $orders['IT-1002']['invoice']);
        self::assertSame([], $orders['IT-1002']['shipment']);
    }

    #[Test]
    public function itListsTheBiggestCreditMemoFirst(): void
    {
        $orderId = $this->fixture->order('IT-1001');
        $this->fixture->creditmemo($orderId, 'IT-4001', 2, 50.00);
        $this->fixture->creditmemo($orderId, 'IT-4002', 2, 250.00);
        $this->fixture->creditmemo($orderId, 'IT-4003', 2, 100.00);

        $result = $this->listDocuments(['document_type' => 'credit_memo', 'sort' => 'highest_total', 'limit' => 1]);
        self::assertSame(['IT-4002'], $this->numbers($result));
    }

    #[Test]
    public function itSortsTheOrdersThatHaveNotBeenShipped(): void
    {
        $shippedOrderId = $this->fixture->order('IT-1001', ['total' => 900.00]);
        $this->fixture->order('IT-1002', ['total' => 50.00]);
        $this->fixture->order('IT-1003', ['total' => 250.00]);
        $this->fixture->shipment($shippedOrderId, 'IT-3001');

        $result = $this->listDocuments([
            'document_type' => 'order',
            'without' => [['document_type' => 'shipment']],
            'sort' => 'highest_total',
            'limit' => 1,
        ]);
        self::assertSame(['IT-1003'], $this->numbers($result));
    }

    #[Test]
    public function itSortsShipmentsByTheTotalOfTheirOrder(): void
    {
        $smallOrderId = $this->fixture->order('IT-1001', ['total' => 50.00]);
        $bigOrderId = $this->fixture->order('IT-1002', ['total' => 250.00]);
        $this->fixture->shipment($smallOrderId, 'IT-3001');
        $this->fixture->shipment($bigOrderId, 'IT-3002');

        $result = $this->listDocuments(['document_type' => 'shipment', 'sort' => 'highest_total', 'limit' => 1]);
        self::assertSame(['IT-1002'], $this->numbers($result));
        self::assertSame(['IT-3002'], array_column($result['documents'][0]['shipment'], 'number'));
    }

    #[Test]
    public function itFindsTheLatestOrderWithAProductBeyondTheLastThirtyDays(): void
    {
        $this->fixture->order('IT-1001', ['sku' => 'VELO-2', 'product' => 'Velora X2']);

        $result = $this->action->execute(
            ['document_type' => 'order', 'product' => 'Velora', 'sort' => 'newest', 'limit' => 1],
            self::ADMIN_USER_ID
        );
        self::assertSame('IT-1001', $result['documents'][0]['order_number']);
    }

    #[Test]
    public function itFindsOrdersByPartOfAnEmailAddress(): void
    {
        $this->fixture->order('IT-1001', ['email' => 'anna.jansen@velora-mail.example']);
        $this->fixture->order('IT-1002', ['email' => 'piet.bakker@example.com']);

        $result = $this->listDocuments(['document_type' => 'order', 'customer' => '@velora-mail.example']);

        self::assertSame(['IT-1001'], $this->numbers($result));
    }

    #[Test]
    public function itRejectsAnInvoiceStatusThatDoesNotExist(): void
    {
        $orderId = $this->fixture->order('IT-1001');
        $this->fixture->invoice($orderId, 'IT-2001');

        $result = $this->listDocuments(['document_type' => 'invoice', 'document_status' => 'pending']);

        self::assertSame('Unknown invoice status "pending". Use one of: open, paid, canceled', $result['error']);
    }

    #[Test]
    public function itRejectsACreditMemoStatusThatDoesNotExist(): void
    {
        $orderId = $this->fixture->order('IT-1001');
        $this->fixture->creditmemo($orderId, 'IT-4001');

        $result = $this->listDocuments(['document_type' => 'credit_memo', 'document_status' => 'paid']);

        self::assertSame(
            'Unknown credit memo status "paid". Use one of: open, refunded, canceled',
            $result['error']
        );
    }

    #[Test]
    public function itFiltersShipmentsByTheTotalOfTheirOrder(): void
    {
        $smallOrderId = $this->fixture->order('IT-1001', ['total' => 50.00]);
        $bigOrderId = $this->fixture->order('IT-1002', ['total' => 600.00]);
        $this->fixture->shipment($smallOrderId, 'IT-3001');
        $this->fixture->shipment($bigOrderId, 'IT-3002');

        $result = $this->listDocuments(['document_type' => 'shipment', 'min_total' => 500]);

        self::assertSame(['IT-3002'], $this->numbers($result));
    }

    #[Test]
    public function itSearchesTheInvoicesOfACustomerInTheLastThirtyDaysOnly(): void
    {
        $orderId = $this->fixture->order('IT-1001', ['lastname' => 'Zwanenburg']);
        $this->fixture->invoice($orderId, 'IT-2001');

        $result = $this->action->execute(
            ['document_type' => 'invoice', 'customer' => 'Zwanenburg'],
            self::ADMIN_USER_ID
        );

        self::assertSame('30days', $result['period']);
        self::assertSame(0, $result['total_count']);
    }

    #[Test]
    public function itRefusesOrdersToAnAdminWhoMayNotViewThem(): void
    {
        $authorization = new FakeAuthorization(['Magento_Sales::sales_order' => true]);
        $action = MagentoObjectManager::get()->create(
            ListDocumentsAction::class,
            ['authorization' => $authorization]
        );

        $result = $action->execute(['document_type' => 'order'], self::ADMIN_USER_ID);

        self::assertSame('You do not have permission to access order documents', $result['error']);
    }

    private function listDocuments(array $params): array
    {
        return $this->action->execute($params + ['period' => self::PERIOD], self::ADMIN_USER_ID);
    }

    private function numbers(array $result): array
    {
        $numbers = array_map(
            fn(array $document) => $document['number'] ?? $document['order_number'],
            $result['documents']
        );
        sort($numbers);

        return $numbers;
    }
}

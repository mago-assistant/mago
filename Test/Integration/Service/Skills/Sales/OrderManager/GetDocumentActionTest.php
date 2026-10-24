<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Integration\Service\Skills\Sales\OrderManager;

use Magento\Framework\App\ResourceConnection;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\GetDocumentAction;
use MagoAssistant\Mago\Test\Integration\Fakes\FakeAuthorization;
use MagoAssistant\Mago\Test\Integration\MagentoObjectManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class GetDocumentActionTest extends TestCase
{
    private const CREATED_AT = '2001-02-10 12:00:00';
    private const ADMIN_USER_ID = 1;

    private SalesDocumentFixture $fixture;
    private GetDocumentAction $action;

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
        $this->action = $objectManager->create(GetDocumentAction::class, ['authorization' => $authorization]);
        $this->fixture->begin();
    }

    protected function tearDown(): void
    {
        $this->fixture->rollBack();
    }

    #[Test]
    public function itGetsEachDocumentTypeByItsNumber(): void
    {
        $orderId = $this->fixture->order('IT-1001');
        $this->fixture->invoice($orderId, 'IT-2001');
        $this->fixture->shipment($orderId, 'IT-3001', 'NordFreight', 'NF123');
        $this->fixture->creditmemo($orderId, 'IT-4001');

        $order = $this->getDocument('order', 'IT-1001');
        $invoice = $this->getDocument('invoice', 'IT-2001');
        $shipment = $this->getDocument('shipment', 'IT-3001');
        $creditmemo = $this->getDocument('credit_memo', 'IT-4001');
        self::assertSame('IT-1001', $order['order_number']);
        self::assertSame('IT-2001', $invoice['number']);
        self::assertSame(['NF123'], $shipment['tracking']);
        self::assertSame('NordFreight', $shipment['carrier']);
        self::assertSame('refunded', $creditmemo['state']);
    }

    #[Test]
    public function itReportsADocumentNumberThatDoesNotExist(): void
    {
        $result = $this->getDocument('credit_memo', 'IT-4999');
        self::assertSame('No credit memo has number IT-4999', $result['error']);
    }

    private function getDocument(string $documentType, string $documentNumber): array
    {
        $params = [
            'document_type' => $documentType,
            'document_number' => $documentNumber,
        ];

        return $this->action->execute($params, self::ADMIN_USER_ID);
    }
}

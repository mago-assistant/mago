<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Form;

use MagoAssistant\Mago\Service\Form\FormPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FormPolicyTest extends TestCase
{
    /**
     * The browser's location.pathname on each sales document page, as Magento's own links reach
     * it: the sales grids open sales/{document}/view, which forwards without changing the URL, the
     * order view opens sales/order_invoice, sales/order_creditmemo and adminhtml/order_shipment.
     *
     * @return array<string, array{string}>
     */
    public static function salesDocumentRoutes(): array
    {
        return [
            'invoice from the invoice grid' => ['/admin/sales/invoice/view/invoice_id/3/'],
            'invoice from the order' => ['/admin/sales/order_invoice/view/invoice_id/3/'],
            'new invoice' => ['/admin/sales/order_invoice/new/order_id/5/'],
            'shipment from the shipment grid' => ['/admin/sales/shipment/view/shipment_id/4/'],
            'shipment from the order' => ['/admin/admin/order_shipment/view/shipment_id/4/'],
            'new shipment' => ['/admin/admin/order_shipment/new/order_id/5/'],
            'credit memo from the credit memo grid' => ['/admin/sales/creditmemo/view/creditmemo_id/6/'],
            'credit memo from the order' => ['/admin/sales/order_creditmemo/view/creditmemo_id/6/'],
            'new credit memo' => ['/admin/sales/order_creditmemo/new/order_id/5/'],
            'new credit memo from an invoice' => ['/admin/sales/order_creditmemo/new/order_id/5/invoice_id/3/'],
            'custom backend front name' => ['/backend/admin/order_shipment/new/order_id/5/'],
        ];
    }

    #[Test]
    #[DataProvider('salesDocumentRoutes')]
    public function itDeniesASalesDocumentPageByItsRoute(string $route): void
    {
        $isDenied = (new FormPolicy())->isDenied('', $route);

        self::assertTrue($isDenied);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function salesDocumentNamespaces(): array
    {
        return [
            'invoice' => ['invoice_form'],
            'shipment' => ['shipment_form'],
            'credit memo' => ['creditmemo_form'],
            'a vendor invoice form' => ['sales_invoice_form'],
        ];
    }

    #[Test]
    #[DataProvider('salesDocumentNamespaces')]
    public function itDeniesASalesDocumentFormByItsNamespace(string $namespace): void
    {
        $isDenied = (new FormPolicy())->isDenied($namespace, '');

        self::assertTrue($isDenied);
    }

    #[Test]
    public function itStillAllowsAProductForm(): void
    {
        $isDenied = (new FormPolicy())->isDenied('product_form', '/admin/catalog/product/edit/id/1/');

        self::assertFalse($isDenied);
    }

    #[Test]
    public function itPublishesTheSalesDocumentPatternsToTheBrowser(): void
    {
        $policy = new FormPolicy();

        self::assertContains('admin/order_shipment', $policy->getDeniedRoutePatterns());
        self::assertContains('creditmemo_form', $policy->getDeniedNamespacePatterns());
    }
}

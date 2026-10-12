<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Form;

use MagoAssistant\Mago\Service\Form\FormPolicy;
use MagoAssistant\Mago\Service\Form\PageContextNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PageContextNormalizerTest extends TestCase
{
    #[Test]
    public function itCarriesTheBrowsersTruncationFlagThrough(): void
    {
        $context = $this->normalize(['truncated' => ['fields' => true]]);

        $this->assertTrue($context->isFieldListTruncated);
    }

    #[Test]
    public function itTreatsAnUntruncatedSnapshotAsComplete(): void
    {
        $context = $this->normalize(['truncated' => ['fields' => false]]);

        $this->assertFalse($context->isFieldListTruncated);
    }

    /**
     * An older browser build, or a payload from anywhere else, simply has no flag. That must read
     * as "not truncated" rather than throwing or defaulting to a warning on every turn.
     */
    #[Test]
    public function itTreatsAMissingTruncationFlagAsComplete(): void
    {
        $context = $this->normalize([]);

        $this->assertFalse($context->isFieldListTruncated);
    }

    #[Test]
    public function itIgnoresATruncationFlagThatIsNotTheExpectedShape(): void
    {
        $context = $this->normalize(['truncated' => 'yes']);

        $this->assertFalse($context->isFieldListTruncated);
    }

    #[Test]
    public function itOnlyTreatsABooleanTrueAsTruncated(): void
    {
        $context = $this->normalize(['truncated' => ['fields' => 'true']]);

        $this->assertFalse($context->isFieldListTruncated);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function salesDocumentRoutes(): array
    {
        return [
            'invoice' => ['/admin/sales/invoice/view/invoice_id/3/'],
            'shipment' => ['/admin/admin/order_shipment/new/order_id/5/'],
            'credit memo' => ['/admin/sales/creditmemo/view/creditmemo_id/6/'],
        ];
    }

    /**
     * A tampered request that sends a snapshot from a sales document page anyway, without the
     * browser's own "denied" flag, so only the server's re-check stands between it and the model.
     */
    #[Test]
    #[DataProvider('salesDocumentRoutes')]
    public function itRefusesASnapshotFromASalesDocumentPage(string $route): void
    {
        $normalizer = new PageContextNormalizer(new FormPolicy());
        $payload = [
            'hasForm' => true,
            'route' => $route,
            'namespace' => 'vendor_document_form',
            'entityType' => 'vendor_document',
            'entityId' => '3',
            'fields' => [],
        ];

        $context = $normalizer->normalize($payload);

        $this->assertNull($context);
        $this->assertTrue($normalizer->isDenied($payload));
    }

    private function normalize(array $extra): \MagoAssistant\Mago\Model\Form\PageContext
    {
        $context = (new PageContextNormalizer(new FormPolicy()))->normalize([
            'hasForm' => true,
            'route' => '/admin/catalog/product/edit/id/1/',
            'namespace' => 'product_form',
            'entityType' => 'product',
            'entityId' => '1',
            'fields' => [],
        ] + $extra);

        $this->assertNotNull($context, 'the payload should normalize to a context');

        return $context;
    }
}

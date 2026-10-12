<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Privacy;

use MagoAssistant\Mago\Service\Privacy\ConversationVault;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Service\Privacy\PrivacyFilter;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\GetDocumentAction;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\ListDocumentsAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "Who placed this order" is the question an order lookup exists for. Dropping the name answered
 * everything except that, so the buyer is masked like any other identifier: a token towards the
 * provider, the real name towards the admin.
 */
final class OrderIdentifierTokenTest extends TestCase
{
    /**
     * @return array{0:PrivacyFilter,1:ConversationVault}
     */
    private function filter(): array
    {
        $vault = new ConversationVault();

        return [new PrivacyFilter($vault, new PiiHeuristic()), $vault];
    }

    /**
     * @param class-string $action
     * @return array<string,array{0:string,1?:string}>
     */
    private function classesOf(string $action): array
    {
        return (new \ReflectionClass($action))->newInstanceWithoutConstructor()->getFieldClassification();
    }

    #[Test]
    public function aListedOrderNamesItsBuyerToTheAdminAndNotToTheProvider(): void
    {
        [$filter, $vault] = $this->filter();

        $out = $filter->filter($this->classesOf(ListDocumentsAction::class), [
            'total_count' => 1,
            'documents' => [[
                'order_number' => '000000563',
                'customer' => 'Jan Jansen',
                'email' => 'jan.jansen@example.com',
                'total' => 49.90,
                'status' => 'processing',
            ]],
        ]);
        $sent = (string)json_encode($out);
        $document = $out['documents'][0];

        self::assertStringNotContainsString('Jan Jansen', $sent);
        self::assertStringNotContainsString('jan.jansen@example.com', $sent);
        self::assertStringNotContainsString('000000563', $sent);
        self::assertStringContainsString('processing', $sent, 'the status is not an identifier');

        self::assertSame('Jan Jansen', $vault->rehydrate($document['customer']));
        self::assertSame('jan.jansen@example.com', $vault->rehydrate($document['email']));
        self::assertSame('000000563', $vault->rehydrate($document['order_number']));
    }

    #[Test]
    public function anAbsentIdentifierIsNotGivenATokenOfItsOwn(): void
    {
        [$filter, $vault] = $this->filter();

        $out = $filter->filter($this->classesOf(ListDocumentsAction::class), [
            'documents' => [['customer' => '', 'email' => null]],
        ]);
        $document = $out['documents'][0];

        self::assertSame('', $document['customer'], 'a guest without a name has nobody to stand for');
        self::assertNull($document['email']);
        self::assertFalse($vault->has('mago://name_1'));
    }

    #[Test]
    public function aShipmentMasksItsNumberBuyerAndTrackingNumbers(): void
    {
        [$filter, $vault] = $this->filter();

        $out = $filter->filter($this->classesOf(GetDocumentAction::class), [
            'number' => '000000012',
            'order_number' => '000000563',
            'customer' => 'Jan Jansen',
            'qty' => 2,
            'tracking' => ['3SABCD1234567', '3SABCD7654321'],
        ]);

        self::assertMatchesRegularExpression('#^mago://document_\d+$#', $out['number']);
        self::assertMatchesRegularExpression('#^mago://name_\d+$#', $out['customer']);
        self::assertSame(2, $out['qty']);
        self::assertSame('3SABCD1234567', $vault->rehydrate($out['tracking'][0]));
        self::assertSame('3SABCD7654321', $vault->rehydrate($out['tracking'][1]));
    }
}

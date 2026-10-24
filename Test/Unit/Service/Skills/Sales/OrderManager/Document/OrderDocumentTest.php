<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Sales\OrderManager\Document;

use ArrayIterator;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DataObject;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\Document\OrderDocument;
use MagoAssistant\Mago\Service\Skills\Sales\OrderManager\Document\VatNumber;
use MagoAssistant\Mago\Service\Time\StoreTime;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Traversable;

final class OrderDocumentTest extends TestCase
{
    /**
     * The fail-closed privacy filter drops an undeclared field silently, so the order document's
     * vat_id only reaches anyone while the classification carries it.
     */
    #[Test]
    public function itReturnsTheBillingVatNumberNormalisedAndMasked(): void
    {
        $documents = $this->describe([
            ['entity_id' => 1, 'billing_vat_id' => '123456789B01', 'billing_country_id' => 'NL'],
            ['entity_id' => 2],
        ]);

        self::assertSame('NL123456789B01', $documents[0]['vat_id']);
        self::assertSame('', $documents[1]['vat_id']);
        self::assertSame([PiiClass::TOKENISE, 'vat'], OrderDocument::FIELD_CLASSIFICATION['vat_id']);
    }

    /**
     * describe() on an order document wired with only what it reads; the collection factories it
     * filters with play no part in turning loaded rows into documents.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function describe(array $rows): array
    {
        $document = (new \ReflectionClass(OrderDocument::class))->newInstanceWithoutConstructor();
        $adminUrl = $this->createMock(SecureAdminUrl::class);
        $orders = new class (array_map(static fn (array $row): DataObject => new DataObject($row), $rows))
            extends AbstractDb {
            /**
             * @param DataObject[] $rows
             */
            public function __construct(private readonly array $rows)
            {
            }

            public function getIterator(): Traversable
            {
                return new ArrayIterator($this->rows);
            }

            public function getResource(): null
            {
                return null;
            }
        };

        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('getConfigTimezone')->willReturn('UTC');

        return (function (AbstractDb $orders, SecureAdminUrl $adminUrl, TimezoneInterface $timezone): array {
            $this->vatNumber = new VatNumber();
            $this->secureAdminUrl = $adminUrl;
            $this->storeTime = new StoreTime($timezone);

            return $this->describe($orders);
        })->call($document, $orders, $adminUrl, $timezone);
    }
}

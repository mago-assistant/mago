<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Privacy;

use MagoAssistant\Mago\Service\Privacy\ConversationVault;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Service\Privacy\PrivacyFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #97: the legal preference is not-sending over masking. Direct identifiers are stripped
 * (never sent); only bare linkable ids are tokenised so the assistant can refer to a row across
 * turns; undeclared is never public. This proves that on the real output shapes of the PII-carrying
 * tools, with the classification maps the filter now receives from the tools themselves.
 */
class PrivacyFilterTest extends TestCase
{
    private const LOOKUP_CUSTOMER_CLASSES = [
        'entity_id' => [PiiClass::TOKENISE, 'customer'],
        'name' => [PiiClass::STRIP],
        'email' => [PiiClass::STRIP],
        'telephone' => [PiiClass::STRIP],
        'country' => [PiiClass::PUBLIC],
        'city' => [PiiClass::PUBLIC],
        'registered' => [PiiClass::PUBLIC],
    ];

    private const LOOKUP_ORDER_CLASSES = [
        'entity_id' => [PiiClass::TOKENISE, 'order'],
        'order_number' => [PiiClass::TOKENISE, 'order'],
        'total' => [PiiClass::PUBLIC],
        'status' => [PiiClass::PUBLIC],
        'customer' => [PiiClass::STRIP],
        'email' => [PiiClass::STRIP],
        'date' => [PiiClass::PUBLIC],
        'sku' => [PiiClass::PUBLIC],
        'name' => [PiiClass::PUBLIC],
        'qty' => [PiiClass::PUBLIC],
        'price' => [PiiClass::PUBLIC],
        'row_total' => [PiiClass::PUBLIC],
    ];

    private const REVIEW_LIST_CLASSES = [
        'review_id' => [PiiClass::TOKENISE, 'review'],
        'title' => [PiiClass::STRIP],
        'nickname' => [PiiClass::STRIP],
        'detail' => [PiiClass::STRIP],
        'product_id' => [PiiClass::PUBLIC],
        'created_at' => [PiiClass::PUBLIC],
        'total' => [PiiClass::PUBLIC],
    ];

    private const WILDCARD_PUBLIC = [PiiClass::ANY => [PiiClass::PUBLIC]];

    private const LOOKUP_CUSTOMER_RESULT = [
        'results' => [
            [
                'entity_id' => 42,
                'name' => 'Jan Jansen',
                'email' => 'jan@example.com',
                'country' => 'NL',
                'city' => 'Amsterdam',
                'telephone' => '0612345678',
                'registered' => '2026-01-05 10:00:00',
                'admin_url' => 'https://shop.test/admin/customer/index/edit/id/42/key/abc123secret/',
            ],
        ],
    ];

    private const LOOKUP_ORDER_RESULT = [
        'order' => [
            'entity_id' => 7,
            'order_number' => '000000549',
            'total' => 149.95,
            'status' => 'processing',
            'customer' => 'Jan Jansen',
            'email' => 'jan@example.com',
            'items' => [
                ['sku' => 'ABC-1', 'name' => 'Helmet', 'qty' => 1, 'price' => 149.95, 'row_total' => 149.95],
            ],
            'date' => '2026-01-06 09:00:00',
            'admin_url' => 'https://shop.test/admin/sales/order/view/order_id/7/key/deadbeef/',
        ],
    ];

    private function filter(?ConversationVault $vault = null): PrivacyFilter
    {
        return new PrivacyFilter($vault ?? new ConversationVault(), new PiiHeuristic());
    }

    #[Test]
    public function itStripsDirectIdentifiersOfACustomerLookup(): void
    {
        $record = $this->filter()->filter(self::LOOKUP_CUSTOMER_CLASSES, self::LOOKUP_CUSTOMER_RESULT)['results'][0];

        self::assertArrayNotHasKey('name', $record);
        self::assertArrayNotHasKey('email', $record);
        self::assertArrayNotHasKey('telephone', $record);
        self::assertArrayNotHasKey('admin_url', $record);
    }

    #[Test]
    public function itLeavesNoRawIdentifierInThePayloadTheLlmSees(): void
    {
        $json = (string)json_encode($this->filter()->filter(self::LOOKUP_CUSTOMER_CLASSES, self::LOOKUP_CUSTOMER_RESULT));

        self::assertStringNotContainsString('Jan Jansen', $json);
        self::assertStringNotContainsString('jan@example.com', $json);
        self::assertStringNotContainsString('0612345678', $json);
        self::assertStringNotContainsString('abc123secret', $json);
    }

    #[Test]
    public function itTokenisesOnlyTheBareCustomerIdSoTheAssistantCanStillReferToTheRow(): void
    {
        $record = $this->filter()->filter(self::LOOKUP_CUSTOMER_CLASSES, self::LOOKUP_CUSTOMER_RESULT)['results'][0];

        self::assertSame('mago://customer_1', $record['entity_id']);
    }

    #[Test]
    public function itKeepsCoarsePublicFieldsSoQueriesLikeCityStillWork(): void
    {
        $record = $this->filter()->filter(self::LOOKUP_CUSTOMER_CLASSES, self::LOOKUP_CUSTOMER_RESULT)['results'][0];

        self::assertSame('Amsterdam', $record['city']);
        self::assertSame('NL', $record['country']);
        self::assertSame('2026-01-05 10:00:00', $record['registered']);
    }

    #[Test]
    public function itStripsOrderCustomerNameAndEmailButKeepsProductLinesAndTokenisesTheOrderId(): void
    {
        $order = $this->filter()->filter(self::LOOKUP_ORDER_CLASSES, self::LOOKUP_ORDER_RESULT)['order'];

        self::assertArrayNotHasKey('customer', $order);
        self::assertArrayNotHasKey('email', $order);
        self::assertSame('mago://order_1', $order['entity_id']);
        self::assertSame('mago://order_2', $order['order_number']);
        self::assertSame('processing', $order['status']);
        self::assertSame('Helmet', $order['items'][0]['name']); // product data is not customer PII
    }

    #[Test]
    public function theSameIdAlwaysGetsTheSameTokenWithinTheConversation(): void
    {
        $vault = new ConversationVault();
        $filter = $this->filter($vault);

        $first = $filter->filter(self::LOOKUP_CUSTOMER_CLASSES, self::LOOKUP_CUSTOMER_RESULT)['results'][0]['entity_id'];
        $second = $filter->filter(self::LOOKUP_CUSTOMER_CLASSES, self::LOOKUP_CUSTOMER_RESULT)['results'][0]['entity_id'];

        self::assertSame($first, $second);
    }

    #[Test]
    public function itStripsReviewFreeTextAndNicknameNeverMasksThem(): void
    {
        $result = $this->filter()->filter(self::REVIEW_LIST_CLASSES, [
            'pending_reviews' => [
                ['review_id' => 5, 'title' => 'Great', 'nickname' => 'Jan', 'detail' => 'Call me on 0612345678', 'product_id' => 3, 'created_at' => '2026-01-01', 'admin_url' => 'x'],
            ],
            'total' => 1,
        ]);
        $review = $result['pending_reviews'][0];

        self::assertArrayNotHasKey('nickname', $review);
        self::assertArrayNotHasKey('title', $review);
        self::assertArrayNotHasKey('detail', $review);
        self::assertSame('mago://review_1', $review['review_id']);
        self::assertSame(3, $review['product_id']);
        self::assertStringNotContainsString('0612345678', (string)json_encode($result));
    }

    #[Test]
    public function aWildcardPublicToolPassesThroughUnchanged(): void
    {
        $aggregate = ['revenue' => 12345.67, 'order_count' => 88, 'top_products' => [['sku' => 'ABC-1', 'units' => 40]]];

        self::assertSame($aggregate, $this->filter()->filter(self::WILDCARD_PUBLIC, $aggregate));
    }

    #[Test]
    public function itKeepsTheErrorEnvelopeSoAFailedLookupCanStillBeExplained(): void
    {
        $result = $this->filter()->filter(self::LOOKUP_ORDER_CLASSES, ['error' => 'Order not found: 000000549']);

        self::assertSame(['error' => 'Order not found: 000000549'], $result);
    }

    #[Test]
    public function itKeepsTheErrorEnvelopeOfAToolThatDeclaresNothing(): void
    {
        $result = $this->filter()->filter([], ['error' => 'Tool blew up']);

        self::assertSame(['error' => 'Tool blew up'], $result);
    }

    #[Test]
    public function aUrlTokenSurvivesBeingWrittenIntoAMarkdownLink(): void
    {
        $vault = new ConversationVault();
        $url = 'https://shop.test/admin/sales/order/view/order_id/660/key/abc123secret/';
        $token = $vault->tokenise($url, 'url');

        // The model writes the token into the target half of a markdown link. A bracketed token
        // loses its brackets there, because "[" already means the link text, and the swap back
        // then matches nothing.
        self::assertSame(
            '[order hier bekijken](' . $url . ')',
            $vault->rehydrate('[order hier bekijken](' . $token . ')')
        );
    }

    #[Test]
    public function itMasksAdminUrlEvenFromAWildcardPublicTool(): void
    {
        $vault = new ConversationVault();
        $url = 'https://shop.test/admin/customer/index/key/abc123secret/';

        $result = $this->filter($vault)->filter(self::WILDCARD_PUBLIC, [
            'label' => 'Customer grid',
            'admin_url' => $url,
        ]);

        // A wildcard-public tool must not be able to make the secret key public by accident. It used
        // to be dropped here; it is masked now, so the panel can still hand the admin a link that
        // opens, but what crosses is the token and never the key.
        self::assertStringNotContainsString('abc123secret', (string)json_encode($result));
        self::assertMatchesRegularExpression('#^mago://url_\d+$#', $result['admin_url']);
        self::assertSame($url, $vault->rehydrate($result['admin_url']));
        self::assertSame('Customer grid', $result['label']);
    }

    #[Test]
    public function itTokenisesTheCustomerIdAndKeepsThePeriodOnCustomerOrders(): void
    {
        $result = $this->filter()->filter([
            'customer_id' => [PiiClass::TOKENISE, 'customer'],
            'period' => [PiiClass::PUBLIC],
            'total_orders' => [PiiClass::PUBLIC],
            'entity_id' => [PiiClass::TOKENISE, 'order'],
            'order_number' => [PiiClass::TOKENISE, 'order'],
            'total' => [PiiClass::PUBLIC],
            'status' => [PiiClass::PUBLIC],
            'items' => [PiiClass::PUBLIC],
            'date' => [PiiClass::PUBLIC],
        ], [
            'customer_id' => 42,
            'period' => '30days',
            'total_orders' => 2,
            'orders' => [['entity_id' => 7, 'order_number' => '000000549', 'total' => 10.0, 'status' => 'x', 'items' => 1, 'date' => 'd']],
        ]);

        self::assertSame('mago://customer_1', $result['customer_id']);
        self::assertSame('30days', $result['period']);
        self::assertSame(2, $result['total_orders']);
        self::assertSame('mago://order_1', $result['orders'][0]['entity_id']);
    }

    #[Test]
    public function itReScrubsARehydratedIdentifierEchoedBackInAPublicField(): void
    {
        // search_orders echoes the query verbatim (classified PUBLIC); a rehydrated email used as
        // the query must not cross to the LLM raw.
        $result = $this->filter()->filter([
            'query' => [PiiClass::PUBLIC],
            'results_count' => [PiiClass::PUBLIC],
        ], [
            'query' => 'jan@example.com',
            'results_count' => 0,
            'orders' => [],
        ]);

        self::assertSame('mago://email_1', $result['query']);
        self::assertStringNotContainsString('jan@example.com', (string)json_encode($result));
    }

    #[Test]
    public function itReScrubsARehydratedIdentifierEchoedBackInTheMessageEnvelope(): void
    {
        $result = $this->filter()->filter(self::LOOKUP_CUSTOMER_CLASSES, [
            'results' => [],
            'message' => 'No customers found matching "jan@example.com"',
        ]);

        self::assertStringNotContainsString('jan@example.com', (string)json_encode($result));
    }

    #[Test]
    public function anExplicitStripKeyIsDroppedEvenWhenItsValueIsANestedArray(): void
    {
        // lookup_order classifies "customer" STRIP; a shape that nests it must not leak the leaves.
        $result = $this->filter()->filter(self::LOOKUP_ORDER_CLASSES, [
            'order' => [
                'entity_id' => 7,
                'customer' => ['name' => 'Jan Jansen', 'email' => 'jan@example.com'],
            ],
        ]);

        self::assertArrayNotHasKey('customer', $result['order']);
        self::assertStringNotContainsString('Jan Jansen', (string)json_encode($result));
    }

    #[Test]
    public function itDefangsAForgedTokenPlantedInWildcardPublicToolOutput(): void
    {
        $result = $this->filter()->filter(self::WILDCARD_PUBLIC, [
            'results' => [['name' => 'Widget mago://email_1 special']],
        ]);

        self::assertSame('Widget (email_1) special', $result['results'][0]['name']);
    }

    #[Test]
    public function aToolThatDeclaresNothingLeaksNothing(): void
    {
        $filtered = $this->filter()->filter([], [
            'customer_email' => 'leak@example.com',
            'nested' => ['phone' => '0612345678'],
        ]);

        self::assertArrayNotHasKey('customer_email', $filtered);
        self::assertSame(['nested' => []], $filtered);
    }

    #[Test]
    public function itConcealsAVaultedOrderNumberEchoedInAKeptAckMessage(): void
    {
        $vault = new ConversationVault();
        $filter = $this->filter($vault);
        $vault->tokenise('000000549', 'order');

        $result = $filter->filter([
            'success' => [PiiClass::PUBLIC],
            'message' => [PiiClass::PUBLIC],
        ], [
            'success' => true,
            'message' => 'Invoice created for order #000000549',
        ]);

        self::assertSame('Invoice created for order #mago://order_1', $result['message']);
    }

    #[Test]
    public function anExplicitStripOnMessageBeatsTheEnvelopeAllowance(): void
    {
        $result = $this->filter()->filter([
            'success' => [PiiClass::PUBLIC],
            'message' => [PiiClass::STRIP],
        ], ['success' => true, 'message' => 'Review #5 has been rejected']);

        self::assertSame(['success' => true], $result);
    }

    #[Test]
    public function aScalarListInheritsTheRuleOfTheKeyItSitsUnder(): void
    {
        $result = $this->filter()->filter([
            'skus' => [PiiClass::PUBLIC],
            'emails' => [PiiClass::STRIP],
        ], [
            'skus' => ['ABC-1', 'DEF-2'],
            'emails' => ['jan@example.com'],
        ]);

        self::assertSame(['ABC-1', 'DEF-2'], $result['skus']);
        self::assertArrayNotHasKey('emails', $result);
    }
}

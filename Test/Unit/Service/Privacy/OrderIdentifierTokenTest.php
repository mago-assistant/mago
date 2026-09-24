<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Privacy;

use MagoAssistant\Mago\Service\Privacy\ConversationVault;
use MagoAssistant\Mago\Service\Privacy\PiiHeuristic;
use MagoAssistant\Mago\Service\Privacy\PrivacyFilter;
use MagoAssistant\Mago\Service\Skills\Analytics\SalesData\LookupOrderAction;
use MagoAssistant\Mago\Service\Skills\Analytics\SalesData\RecentOrdersAction;
use MagoAssistant\Mago\Service\Skills\Analytics\SalesData\SearchOrdersAction;
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
    public function anOrderLookupNamesItsBuyerToTheAdminAndNotToTheProvider(): void
    {
        [$filter, $vault] = $this->filter();

        $out = $filter->filter($this->classesOf(LookupOrderAction::class), ['order' => [
            'order_number' => '000000563',
            'customer' => 'Jan Jansen',
            'email' => 'jan.jansen@example.com',
            'total' => 49.90,
            'status' => 'processing',
        ]]);
        $sent = (string)json_encode($out);

        self::assertStringNotContainsString('Jan Jansen', $sent);
        self::assertStringNotContainsString('jan.jansen@example.com', $sent);
        self::assertStringContainsString('processing', $sent, 'the status is not an identifier');

        self::assertSame('Jan Jansen', $vault->rehydrate($out['order']['customer']));
        self::assertSame('jan.jansen@example.com', $vault->rehydrate($out['order']['email']));
    }

    #[Test]
    public function anAbsentIdentifierIsNotGivenATokenOfItsOwn(): void
    {
        [$filter, $vault] = $this->filter();

        $out = $filter->filter(
            $this->classesOf(\MagoAssistant\Mago\Service\Skills\Analytics\SalesData\CustomerOrdersAction::class),
            ['customer_id' => null, 'customer' => '']
        );

        self::assertNull($out['customer_id'], 'a guest has no customer to stand for');
        self::assertSame('', $out['customer']);
        self::assertFalse($vault->has('mago://customer_1'));
    }

    #[Test]
    public function aSearchAndARecentListNameTheirBuyersTheSameWay(): void
    {
        foreach ([SearchOrdersAction::class, RecentOrdersAction::class] as $action) {
            [$filter, $vault] = $this->filter();

            $out = $filter->filter($this->classesOf($action), [
                'rows' => [['order_number' => '000000563', 'customer' => 'Jan Jansen']],
            ]);
            $row = $out['rows'][0];

            self::assertMatchesRegularExpression('#^mago://name_\d+$#', $row['customer'], $action);
            self::assertSame('Jan Jansen', $vault->rehydrate($row['customer']), $action);
        }
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Catalog\ProductLinks;

use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\LinkPlanner;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\LinkRequest;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\LinkType;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\ProductLink;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeProductLinkGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #326: product_links works out its writes from the current links, so the card, the
 * refusal and the save agree, and an existing link never loses its position.
 */
final class LinkPlannerTest extends TestCase
{
    #[Test]
    public function itAddsEachLinkedSkuToEveryProductAfterItsLastPosition(): void
    {
        $gateway = (new FakeProductLinkGateway())->withProducts('CAM-1', 'CAM-2', 'LENS', 'MOUNT')
            ->withLink('CAM-1', LinkType::Related, 'MOUNT', 4);

        $plan = (new LinkPlanner($gateway))->planAdditions($this->request([
            'skus' => ['CAM-1', 'CAM-2'], 'linked_skus' => ['LENS'], 'link_type' => 'related',
        ]));

        self::assertSame(['CAM-1 -related-> LENS @5', 'CAM-2 -related-> LENS @1'], $this->describe($plan->changes()->saves));
    }

    #[Test]
    public function itSkipsALinkThatAlreadyExistsSoItKeepsItsPosition(): void
    {
        $gateway = (new FakeProductLinkGateway())->withProducts('CAM-1', 'LENS', 'MOUNT')
            ->withLink('CAM-1', LinkType::Crosssell, 'LENS', 2);

        $changes = (new LinkPlanner($gateway))->planAdditions($this->request([
            'skus' => ['CAM-1'], 'linked_skus' => ['LENS', 'MOUNT'], 'link_type' => 'crosssell',
        ]))->changes();

        self::assertSame(['CAM-1 -crosssell-> MOUNT @3'], $this->describe($changes->saves));
        self::assertSame(['CAM-1 -crosssell-> LENS @2'], $this->describe($changes->skipped));
    }

    #[Test]
    public function itAlsoLinksBackWhenTwoWay(): void
    {
        $gateway = (new FakeProductLinkGateway())->withProducts('CAM-1', 'LENS');

        $changes = (new LinkPlanner($gateway))->planAdditions($this->request([
            'skus' => ['CAM-1'], 'linked_skus' => ['LENS'], 'link_type' => 'related', 'two_way' => true,
        ]))->changes();

        self::assertSame(['CAM-1 -related-> LENS @1', 'LENS -related-> CAM-1 @1'], $this->describe($changes->saves));
    }

    #[Test]
    public function aGroupOfProductsCanBeRelatedToEachOtherWithoutLinkingAnyToItself(): void
    {
        $gateway = (new FakeProductLinkGateway())->withProducts('A', 'B', 'C');

        $changes = (new LinkPlanner($gateway))->planAdditions($this->request([
            'skus' => ['A', 'B', 'C'], 'linked_skus' => ['A', 'B', 'C'], 'link_type' => 'related',
        ]))->changes();

        self::assertSame(
            ['A -related-> B @1', 'A -related-> C @2', 'B -related-> A @1', 'B -related-> C @2', 'C -related-> A @1', 'C -related-> B @2'],
            $this->describe($changes->saves)
        );
    }

    #[Test]
    public function itUsesTheCatalogSpellingOfASkuGivenInAnotherCase(): void
    {
        $gateway = (new FakeProductLinkGateway())->withProducts('CAM-1', 'LENS')
            ->withLink('CAM-1', LinkType::Related, 'LENS', 1);

        $plan = (new LinkPlanner($gateway))->planAdditions($this->request([
            'skus' => ['cam-1'], 'linked_skus' => ['lens'], 'link_type' => 'related',
        ]));

        self::assertSame('Nothing to add: every requested related link already exists.', $plan->refusal());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function refusedAdditions(): array
    {
        $valid = ['skus' => ['CAM-1'], 'linked_skus' => ['LENS'], 'link_type' => 'related'];

        return [
            'no link type' => [['link_type' => null] + $valid, 'link_type is required: one of related, upsell, crosssell.'],
            'an unknown link type' => [['link_type' => 'similar'] + $valid, 'Unknown link_type "similar". Use one of related, upsell, crosssell.'],
            'grouped children' => [['link_type' => 'associated'] + $valid, 'Grouped product children (associated) are not managed by product_links: they decide what the grouped product sells. Change them on the grouped product\'s edit page.'],
            'no products' => [['skus' => []] + $valid, 'skus is required: the SKUs of the products to change.'],
            'no linked skus' => [['linked_skus' => []] + $valid, 'linked_skus is required: the SKUs to link.'],
            'an up-sell both ways' => [['link_type' => 'upsell', 'two_way' => true] + $valid, 'two_way is not available for up-sells: an up-sell points at a better product, and two products cannot each be the better one.'],
            'too many products' => [['skus' => array_map(static fn (int $i): string => 'P' . $i, range(1, 51))] + $valid, 'At most 50 products per call, 51 were given. Split the change into several calls.'],
            'too many linked skus' => [['linked_skus' => array_map(static fn (int $i): string => 'L' . $i, range(1, 21))] + $valid, 'At most 20 linked_skus per call, 21 were given. Split the change into several calls.'],
            'an unknown sku' => [['linked_skus' => ['LENS', 'GHOST']] + $valid, 'Unknown SKU: GHOST. Look the products up with product_data.search; never guess a SKU.'],
            'only itself' => [['linked_skus' => ['CAM-1']] + $valid, 'A product cannot be linked to itself.'],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[Test]
    #[DataProvider('refusedAdditions')]
    public function itRefusesAnAdditionItCannotMake(array $params, string $refusal): void
    {
        $gateway = (new FakeProductLinkGateway())->withProducts('CAM-1', 'LENS');

        $plan = (new LinkPlanner($gateway))->planAdditions($this->request($params));

        self::assertSame($refusal, $plan->refusal());
    }

    #[Test]
    public function itRemovesOnlyTheNamedLinksAndReportsTheOnesThatWereNotLinked(): void
    {
        $gateway = (new FakeProductLinkGateway())->withProducts('CAM-1', 'LENS', 'MOUNT', 'BAG')
            ->withLink('CAM-1', LinkType::Upsell, 'LENS', 1)
            ->withLink('CAM-1', LinkType::Upsell, 'MOUNT', 2);

        $changes = (new LinkPlanner($gateway))->planRemovals($this->request([
            'skus' => ['CAM-1'], 'linked_skus' => ['LENS', 'BAG'], 'link_type' => 'upsell',
        ]))->changes();

        self::assertSame(['CAM-1 -upsell-> LENS @1'], $this->describe($changes->deletes));
        self::assertSame(['CAM-1 -upsell-> BAG @0'], $this->describe($changes->skipped));
    }

    #[Test]
    public function itRemovesEveryLinkOfTheTypeWhenNoLinkedSkusAreGiven(): void
    {
        $gateway = (new FakeProductLinkGateway())->withProducts('CAM-1', 'CAM-2', 'LENS', 'MOUNT')
            ->withLink('CAM-1', LinkType::Crosssell, 'LENS', 1)
            ->withLink('CAM-2', LinkType::Crosssell, 'MOUNT', 1)
            ->withLink('CAM-1', LinkType::Related, 'MOUNT', 1);

        $changes = (new LinkPlanner($gateway))->planRemovals($this->request([
            'skus' => ['CAM-1', 'CAM-2'], 'link_type' => 'crosssell',
        ]))->changes();

        self::assertSame(['CAM-1 -crosssell-> LENS @1', 'CAM-2 -crosssell-> MOUNT @1'], $this->describe($changes->deletes));
    }

    #[Test]
    public function itAlsoRemovesTheLinksPointingBackWhenTwoWay(): void
    {
        $gateway = (new FakeProductLinkGateway())->withProducts('CAM-1', 'LENS', 'OTHER')
            ->withLink('CAM-1', LinkType::Related, 'LENS', 1)
            ->withLink('LENS', LinkType::Related, 'CAM-1', 3)
            ->withLink('LENS', LinkType::Related, 'OTHER', 1);

        $changes = (new LinkPlanner($gateway))->planRemovals($this->request([
            'skus' => ['CAM-1'], 'link_type' => 'related', 'two_way' => true,
        ]))->changes();

        self::assertSame(['CAM-1 -related-> LENS @1', 'LENS -related-> CAM-1 @3'], $this->describe($changes->deletes));
    }

    #[Test]
    public function itRefusesARemovalWhenNoneOfTheLinksExist(): void
    {
        $gateway = (new FakeProductLinkGateway())->withProducts('CAM-1', 'LENS');

        $plan = (new LinkPlanner($gateway))->planRemovals($this->request([
            'skus' => ['CAM-1'], 'linked_skus' => ['LENS'], 'link_type' => 'crosssell',
        ]));

        self::assertSame('Nothing to remove: none of these cross-sell links exist.', $plan->refusal());
    }

    #[Test]
    public function itPutsTheListedLinksFirstAndKeepsTheOthersInTheirOrder(): void
    {
        $gateway = (new FakeProductLinkGateway())->withProducts('CAM-1', 'A', 'B', 'C', 'D')
            ->withLink('CAM-1', LinkType::Related, 'A', 1)
            ->withLink('CAM-1', LinkType::Related, 'B', 2)
            ->withLink('CAM-1', LinkType::Related, 'C', 3)
            ->withLink('CAM-1', LinkType::Related, 'D', 4);

        $changes = (new LinkPlanner($gateway))->planPositions($this->request([
            'sku' => 'CAM-1', 'linked_skus' => ['C', 'A'], 'link_type' => 'related',
        ]))->changes();

        self::assertSame(['CAM-1 -related-> A @2', 'CAM-1 -related-> B @3', 'CAM-1 -related-> C @1'], $this->describe($changes->saves));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function refusedPositions(): array
    {
        return [
            'a sku that is not linked' => [
                ['sku' => 'CAM-1', 'linked_skus' => ['B', 'LENS'], 'link_type' => 'related'],
                'Not linked as related to CAM-1: LENS. Add them with add_links first, or leave them out.',
            ],
            'already in this order' => [
                ['sku' => 'CAM-1', 'linked_skus' => ['A'], 'link_type' => 'related'],
                'The related products of CAM-1 are already in this order.',
            ],
            'more than one product' => [
                ['skus' => ['CAM-1', 'LENS'], 'linked_skus' => ['A'], 'link_type' => 'related'],
                'Give exactly one product in sku: positions are set per product.',
            ],
            'no order' => [
                ['sku' => 'CAM-1', 'link_type' => 'related'],
                'linked_skus is required: the SKUs to link.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[Test]
    #[DataProvider('refusedPositions')]
    public function itRefusesPositionsItCannotSet(array $params, string $refusal): void
    {
        $gateway = (new FakeProductLinkGateway())->withProducts('CAM-1', 'A', 'B', 'LENS')
            ->withLink('CAM-1', LinkType::Related, 'A', 1)
            ->withLink('CAM-1', LinkType::Related, 'B', 2);

        $plan = (new LinkPlanner($gateway))->planPositions($this->request($params));

        self::assertSame($refusal, $plan->refusal());
    }

    /**
     * @param array<string, mixed> $params
     */
    private function request(array $params): LinkRequest
    {
        return LinkRequest::fromParams($params);
    }

    /**
     * @param list<ProductLink> $links
     * @return list<string>
     */
    private function describe(array $links): array
    {
        $described = array_map(
            static fn (ProductLink $link): string => $link->productSku . ' -' . $link->type->value . '-> '
                . $link->linkedSku . ' @' . $link->position,
            $links
        );
        sort($described);

        return $described;
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Catalog\ProductLinks;

use MagoAssistant\Mago\Api\Skill\IrreversibleActionInterface;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\ChangeLines;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\LinkPlanner;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\LinkType;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\RemoveLinksAction;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeProductLinkGateway;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #326: remove_links lists every link it removes on the caution card, also the ones pointing
 * back from products outside the request, and removes exactly those.
 */
final class RemoveLinksActionTest extends TestCase
{
    private const PARAMS = [
        'action' => 'remove_links',
        'skus' => ['CAM-1'],
        'link_type' => 'related',
        'two_way' => true,
    ];

    #[Test]
    public function theCardListsEveryLinkItRemovesIncludingTheOnesPointingBack(): void
    {
        $cautions = $this->action($this->catalog())->getCautions(self::PARAMS, 1);

        self::assertSame([
            'Removes 3 related links from 2 products. Their positions are lost; a link added again goes to the end of the list.',
            'CAM-1 (Camera one): removes LENS, MOUNT.',
            'LENS (Lens): removes CAM-1.',
        ], $cautions);
    }

    #[Test]
    public function itRemovesTheLinksTheCardListedAndLeavesTheRest(): void
    {
        $gateway = $this->catalog();

        $result = $this->action($gateway)->execute(self::PARAMS, 1);

        self::assertSame(['CAM-1 -upsell-> LENS @1', 'LENS -related-> BAG @1'], $gateway->linkSummary());
        self::assertSame(3, $result['removed_count']);
        self::assertSame('Removed 3 related links from 2 products.', $result['message']);
    }

    #[Test]
    public function itUsesTheCautionCardNotTheIrreversibleOne(): void
    {
        self::assertNotInstanceOf(IrreversibleActionInterface::class, $this->action($this->catalog()));
    }

    private function catalog(): FakeProductLinkGateway
    {
        return (new FakeProductLinkGateway())
            ->withProduct('CAM-1', 'Camera one')
            ->withProduct('LENS', 'Lens')
            ->withProduct('MOUNT', 'Mount')
            ->withProduct('BAG', 'Bag')
            ->withLink('CAM-1', LinkType::Related, 'LENS', 1)
            ->withLink('CAM-1', LinkType::Related, 'MOUNT', 2)
            ->withLink('CAM-1', LinkType::Upsell, 'LENS', 1)
            ->withLink('LENS', LinkType::Related, 'CAM-1', 2)
            ->withLink('LENS', LinkType::Related, 'BAG', 1);
    }

    private function action(FakeProductLinkGateway $gateway): RemoveLinksAction
    {
        return new RemoveLinksAction(new LinkPlanner($gateway), $gateway, new ChangeLines());
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Catalog\ProductLinks;

use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\ChangeLines;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\LinkPlanner;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\LinkType;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\SetPositionsAction;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeProductLinkGateway;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #326: set_positions shows every move on the card and writes only the links that move.
 */
final class SetPositionsActionTest extends TestCase
{
    private const PARAMS = ['action' => 'set_positions', 'sku' => 'CAM-1', 'linked_skus' => ['C'], 'link_type' => 'upsell'];

    #[Test]
    public function theCardShowsEveryMove(): void
    {
        $cautions = $this->action($this->catalog())->getCautions(self::PARAMS, 1);

        self::assertSame([
            'Changes the order of the up-sell products of CAM-1 (Camera one):',
            'C to position 1, A to position 2, B to position 3.',
        ], $cautions);
    }

    #[Test]
    public function itWritesTheNewOrder(): void
    {
        $gateway = $this->catalog();

        $result = $this->action($gateway)->execute(self::PARAMS, 1);

        self::assertSame(['CAM-1 -upsell-> A @2', 'CAM-1 -upsell-> B @3', 'CAM-1 -upsell-> C @1'], $gateway->linkSummary());
        self::assertSame(
            [['sku' => 'C', 'position' => 1], ['sku' => 'A', 'position' => 2], ['sku' => 'B', 'position' => 3]],
            $result['moved']
        );
    }

    private function catalog(): FakeProductLinkGateway
    {
        return (new FakeProductLinkGateway())
            ->withProduct('CAM-1', 'Camera one')
            ->withProducts('A', 'B', 'C')
            ->withLink('CAM-1', LinkType::Upsell, 'A', 1)
            ->withLink('CAM-1', LinkType::Upsell, 'B', 2)
            ->withLink('CAM-1', LinkType::Upsell, 'C', 3);
    }

    private function action(FakeProductLinkGateway $gateway): SetPositionsAction
    {
        return new SetPositionsAction(new LinkPlanner($gateway), $gateway, new ChangeLines());
    }
}

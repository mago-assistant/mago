<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Catalog\ProductLinks;

use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\GetLinksAction;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\LinkType;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeBackendUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeProductLinkGateway;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #326: get_links lists the links of one product per type in position order.
 */
final class GetLinksActionTest extends TestCase
{
    #[Test]
    public function itListsEveryTypeInPositionOrderWithTheStatusOfEachLinkedProduct(): void
    {
        $result = $this->action()->execute(['sku' => 'cam-1'], 1);

        self::assertSame('CAM-1', $result['sku']);
        self::assertSame('https://shop.test/admin/catalog/product/edit/', $result['admin_url']);
        self::assertSame(
            [['link_type' => 'related', 'count' => 2], ['link_type' => 'upsell', 'count' => 0], ['link_type' => 'crosssell', 'count' => 1]],
            $result['totals']
        );
        self::assertSame([
            ['link_type' => 'related', 'sku' => 'MOUNT', 'name' => 'Mount', 'status' => 'disabled', 'position' => 1],
            ['link_type' => 'related', 'sku' => 'LENS', 'name' => 'Lens', 'status' => 'enabled', 'position' => 2],
            ['link_type' => 'crosssell', 'sku' => 'BAG', 'name' => 'Bag', 'status' => 'enabled', 'position' => 1],
        ], $result['links']);
        self::assertFalse($result['is_partial']);
    }

    #[Test]
    public function aLimitCutsTheListButNotTheTotals(): void
    {
        $result = $this->action()->execute(['sku' => 'CAM-1', 'link_type' => 'related', 'limit' => 1], 1);

        self::assertSame([['link_type' => 'related', 'count' => 2]], $result['totals']);
        self::assertSame(['MOUNT'], array_column($result['links'], 'sku'));
        self::assertSame(1, $result['shown']);
        self::assertTrue($result['is_partial']);
    }

    #[Test]
    public function anUnknownSkuOrLinkTypeIsAnError(): void
    {
        self::assertSame(
            ['error' => 'Unknown SKU: GHOST. Look the product up with product_data.search; never guess a SKU.'],
            $this->action()->execute(['sku' => 'GHOST'], 1)
        );
        self::assertSame(
            ['error' => 'Unknown link_type "similar". Use one of related, upsell, crosssell.'],
            $this->action()->execute(['sku' => 'CAM-1', 'link_type' => 'similar'], 1)
        );
    }

    private function action(): GetLinksAction
    {
        $gateway = (new FakeProductLinkGateway())
            ->withProduct('CAM-1', 'Camera one')
            ->withProduct('LENS', 'Lens')
            ->withProduct('MOUNT', 'Mount', false)
            ->withProduct('BAG', 'Bag')
            ->withLink('CAM-1', LinkType::Related, 'LENS', 2)
            ->withLink('CAM-1', LinkType::Related, 'MOUNT', 1)
            ->withLink('CAM-1', LinkType::Crosssell, 'BAG', 1);

        return new GetLinksAction($gateway, new SecureAdminUrl(new FakeBackendUrl()));
    }
}

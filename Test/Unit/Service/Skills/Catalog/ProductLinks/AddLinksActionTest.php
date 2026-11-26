<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Catalog\ProductLinks;

use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\AddLinksAction;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\ChangeLines;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\LinkPlanner;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\LinkType;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeProductLinkGateway;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #326: add_links previews every link it adds on the caution card and writes exactly those.
 */
final class AddLinksActionTest extends TestCase
{
    private const PARAMS = [
        'action' => 'add_links',
        'skus' => ['CAM-1', 'CAM-2'],
        'linked_skus' => ['LENS', 'MOUNT'],
        'link_type' => 'crosssell',
    ];

    #[Test]
    public function theCardNamesEveryLinkItAddsAndEveryLinkThatAlreadyExists(): void
    {
        $action = $this->action($this->catalog()->withLink('CAM-2', LinkType::Crosssell, 'MOUNT', 1));

        $cautions = $action->getCautions(self::PARAMS, 1);

        self::assertSame([
            'Adds 3 cross-sell links to 2 products. The storefront shows them right away.',
            'CAM-1 (Camera one): adds LENS, MOUNT.',
            'CAM-2 (Camera two): adds LENS; already linked MOUNT.',
        ], $cautions);
    }

    #[Test]
    public function itWritesTheLinksTheCardNamed(): void
    {
        $gateway = $this->catalog()->withLink('CAM-2', LinkType::Crosssell, 'MOUNT', 1);

        $result = $this->action($gateway)->execute(self::PARAMS, 1);

        self::assertSame([
            'CAM-1 -crosssell-> LENS @1',
            'CAM-1 -crosssell-> MOUNT @2',
            'CAM-2 -crosssell-> LENS @2',
            'CAM-2 -crosssell-> MOUNT @1',
        ], $gateway->linkSummary());
        self::assertTrue($result['success']);
        self::assertSame(3, $result['added_count']);
        self::assertSame(1, $result['already_linked_count']);
        self::assertSame(
            [['sku' => 'CAM-1', 'added' => ['LENS', 'MOUNT'], 'already_linked' => []],
                ['sku' => 'CAM-2', 'added' => ['LENS'], 'already_linked' => ['MOUNT']]],
            $result['products']
        );
    }

    #[Test]
    public function aCallThatWouldBeRefusedIsRefusedBeforeTheCardAndWritesNothing(): void
    {
        $gateway = $this->catalog();
        $params = ['linked_skus' => ['GHOST']] + self::PARAMS;

        $refusal = $this->action($gateway)->findRefusal($params);
        $result = $this->action($gateway)->execute($params, 1);

        $expected = ['error' => 'Unknown SKU: GHOST. Look the products up with product_data.search; never guess a SKU.'];
        self::assertSame($expected, $refusal);
        self::assertSame($expected, $result);
        self::assertSame([], $this->action($gateway)->getCautions($params, 1));
        self::assertSame([], $gateway->appliedChanges());
    }

    #[Test]
    public function aFailedWriteIsReportedAsAnError(): void
    {
        $result = $this->action($this->catalog()->givenWritesFail())->execute(self::PARAMS, 1);

        self::assertSame(['error' => 'The product links could not be saved, nothing was changed: boom'], $result);
    }

    #[Test]
    public function itAsksForTheCatalogProductsResource(): void
    {
        $action = $this->action($this->catalog());

        self::assertSame('Magento_Catalog::products', $action->getAclResource());
        self::assertFalse($action->isReadOnly());
    }

    private function catalog(): FakeProductLinkGateway
    {
        return (new FakeProductLinkGateway())
            ->withProduct('CAM-1', 'Camera one')
            ->withProduct('CAM-2', 'Camera two')
            ->withProduct('LENS', 'Lens')
            ->withProduct('MOUNT', 'Mount');
    }

    private function action(FakeProductLinkGateway $gateway): AddLinksAction
    {
        return new AddLinksAction(new LinkPlanner($gateway), $gateway, new ChangeLines());
    }
}

<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Catalog;

use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\AddLinksAction;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\ChangeLines;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\GetLinksAction;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\LinkPlanner;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\RemoveLinksAction;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\SetPositionsAction;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAuthorization;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeBackendUrl;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeProductLinkGateway;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #326: the product_links skill is gated by the products screen, reads without asking and
 * asks with the caution card before every write.
 */
final class ProductLinksTest extends TestCase
{
    private const WRITES = ['add_links', 'remove_links', 'set_positions'];

    #[Test]
    public function theSkillAndEveryWriteAreGatedByTheProductsResource(): void
    {
        $skill = $this->skill((new FakeProductLinkGateway())->withProducts('CAM-1', 'LENS'));

        self::assertSame('Magento_Catalog::products', $skill->getMagentoAcl());
        self::assertSame(
            array_fill(0, count(self::WRITES), 'Magento_Catalog::products'),
            array_map(static fn (string $action): ?string => $skill->getActionAcl(['action' => $action]), self::WRITES)
        );
    }

    #[Test]
    public function readingRunsWithoutConfirmationAndEveryWriteAsksWithTheCautionCard(): void
    {
        $skill = $this->skill((new FakeProductLinkGateway())->withProducts('CAM-1', 'LENS'));
        $add = ['action' => 'add_links', 'skus' => ['CAM-1'], 'linked_skus' => ['LENS'], 'link_type' => 'related'];

        self::assertTrue($skill->isReadOnlyAction(['action' => 'get_links']));
        self::assertSame(
            [false, false, false],
            array_map(static fn (string $action): bool => $skill->isReadOnlyAction(['action' => $action]), self::WRITES)
        );
        self::assertFalse($skill->isIrreversibleAction($add));
        self::assertCount(2, $skill->getCautions($add, 1));
        self::assertNull($skill->findRefusal($add));
    }

    #[Test]
    public function everyActionSharesOneDefinitionOfEachParameter(): void
    {
        $properties = $this->skill(new FakeProductLinkGateway())->getParameterSchema()['properties'];

        self::assertSame(
            ['action', 'sku', 'link_type', 'limit', 'skus', 'linked_skus', 'two_way'],
            array_keys($properties)
        );
        self::assertSame(['get_links', 'add_links', 'remove_links', 'set_positions'], $properties['action']['enum']);
    }

    private function skill(FakeProductLinkGateway $gateway): ProductLinks
    {
        $planner = new LinkPlanner($gateway);
        $lines = new ChangeLines();

        return new ProductLinks(new FakeAuthorization(), [
            'get_links' => new GetLinksAction($gateway, new SecureAdminUrl(new FakeBackendUrl())),
            'add_links' => new AddLinksAction($planner, $gateway, $lines),
            'remove_links' => new RemoveLinksAction($planner, $gateway, $lines),
            'set_positions' => new SetPositionsAction($planner, $gateway, $lines),
        ]);
    }
}

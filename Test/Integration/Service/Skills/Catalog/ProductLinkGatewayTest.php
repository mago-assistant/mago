<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Integration\Service\Skills\Catalog;

use Magento\Framework\App\ResourceConnection;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\LinkChangeSet;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\LinkType;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\LinkWriteFailedException;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\ProductLink;
use MagoAssistant\Mago\Service\Skills\Catalog\ProductLinks\ProductLinkGateway;
use MagoAssistant\Mago\Test\Integration\MagentoObjectManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #326: product_links writes one link at a time through the link repository. Against the
 * real tables, a write must leave the other links of the product, their positions and grouped
 * children alone, and a failing batch must write nothing. Every test restores what it changed.
 */
final class ProductLinkGatewayTest extends TestCase
{
    private const LINK_TYPE_GROUPED = 3;

    /** @var list<LinkChangeSet> undo after each test */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        array_map(fn (LinkChangeSet $changes) => $this->gateway()->apply($changes), array_reverse($this->cleanup));
        $this->cleanup = [];
    }

    #[Test]
    public function addingALinkLeavesTheOtherLinksAndTheirPositionsAlone(): void
    {
        [$owner, $before] = $this->productWithRelatedLinks();
        $newSku = $this->skuNotLinkedFrom($owner, $before);
        $added = new ProductLink($owner, LinkType::Related, $newSku, max(array_map(static fn (ProductLink $l): int => $l->position, $before)) + 1);

        $this->applyAndUndo(new LinkChangeSet(LinkType::Related, [$added]), new LinkChangeSet(LinkType::Related, [], [$added]));

        self::assertSame(
            [...$this->describe($before), $this->describe([$added])[0]],
            $this->describe($this->gateway()->findLinks([$owner], LinkType::Related))
        );
    }

    #[Test]
    public function removingALinkLeavesTheOtherLinksAlone(): void
    {
        [$owner, $before] = $this->productWithRelatedLinks();
        $removed = $before[0];

        $this->applyAndUndo(new LinkChangeSet(LinkType::Related, [], [$removed]), new LinkChangeSet(LinkType::Related, [$removed]));

        self::assertSame(
            array_slice($this->describe($before), 1),
            $this->describe($this->gateway()->findLinks([$owner], LinkType::Related))
        );
    }

    #[Test]
    public function aRelatedLinkOnAGroupedProductLeavesItsChildrenAlone(): void
    {
        $grouped = $this->connection()->fetchOne(
            'SELECT e.sku FROM catalog_product_link l JOIN catalog_product_entity e ON e.entity_id = l.product_id'
            . ' WHERE l.link_type_id = ? LIMIT 1',
            [self::LINK_TYPE_GROUPED]
        );
        if ($grouped === false) {
            self::markTestSkipped('Needs a grouped product with children');
        }
        $childrenBefore = $this->groupedChildCount((string)$grouped);
        $link = new ProductLink((string)$grouped, LinkType::Related, $this->skuNotLinkedFrom((string)$grouped, $this->gateway()->findLinks([(string)$grouped], LinkType::Related)), 1);

        $this->applyAndUndo(new LinkChangeSet(LinkType::Related, [$link]), new LinkChangeSet(LinkType::Related, [], [$link]));

        self::assertSame($childrenBefore, $this->groupedChildCount((string)$grouped));
    }

    #[Test]
    public function aBatchWithOneBadLinkWritesNothing(): void
    {
        [$owner, $before] = $this->productWithRelatedLinks();
        $good = new ProductLink($owner, LinkType::Related, $this->skuNotLinkedFrom($owner, $before), 99);
        $bad = new ProductLink($owner, LinkType::Related, 'MAGO-326-DOES-NOT-EXIST', 100);

        try {
            $this->gateway()->apply(new LinkChangeSet(LinkType::Related, [$good, $bad]));
            $this->cleanup[] = new LinkChangeSet(LinkType::Related, [], [$good]);
            self::fail('A link to an unknown SKU was saved');
        } catch (LinkWriteFailedException) {
            self::assertSame($this->describe($before), $this->describe($this->gateway()->findLinks([$owner], LinkType::Related)));
        }
    }

    private function applyAndUndo(LinkChangeSet $changes, LinkChangeSet $undo): void
    {
        $this->gateway()->apply($changes);
        $this->cleanup[] = $undo;
    }

    /**
     * @return array{0: string, 1: list<ProductLink>}
     */
    private function productWithRelatedLinks(): array
    {
        $sku = $this->connection()->fetchOne(
            'SELECT e.sku FROM catalog_product_link l JOIN catalog_product_entity e ON e.entity_id = l.product_id'
            . ' WHERE l.link_type_id = 1 GROUP BY e.sku HAVING COUNT(*) >= 2 ORDER BY e.sku LIMIT 1'
        );
        if ($sku === false) {
            self::markTestSkipped('Needs a product with at least two related products');
        }

        return [(string)$sku, $this->gateway()->findLinks([(string)$sku], LinkType::Related)];
    }

    /**
     * @param list<ProductLink> $links
     */
    private function skuNotLinkedFrom(string $owner, array $links): string
    {
        $taken = [$owner, ...array_map(static fn (ProductLink $link): string => $link->linkedSku, $links)];

        return (string)$this->connection()->fetchOne(
            $this->connection()->select()->from('catalog_product_entity', ['sku'])
                ->where('type_id = ?', 'simple')
                ->where('sku NOT IN (?)', $taken)
                ->order('entity_id ASC')
                ->limit(1)
        );
    }

    private function groupedChildCount(string $sku): int
    {
        return (int)$this->connection()->fetchOne(
            'SELECT COUNT(*) FROM catalog_product_link l JOIN catalog_product_entity e ON e.entity_id = l.product_id'
            . ' WHERE l.link_type_id = ? AND e.sku = ?',
            [self::LINK_TYPE_GROUPED, $sku]
        );
    }

    /**
     * @param list<ProductLink> $links
     * @return list<string>
     */
    private function describe(array $links): array
    {
        return array_map(
            static fn (ProductLink $link): string => $link->productSku . ' -> ' . $link->linkedSku . ' @' . $link->position,
            $links
        );
    }

    private function gateway(): ProductLinkGateway
    {
        return MagentoObjectManager::get()->get(ProductLinkGateway::class);
    }

    private function connection(): \Magento\Framework\DB\Adapter\AdapterInterface
    {
        return MagentoObjectManager::get()->get(ResourceConnection::class)->getConnection();
    }
}

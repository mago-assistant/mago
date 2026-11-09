<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Integration\Model\ResourceModel\Conversation;

use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\View\Element\UiComponent\DataProvider\DataProvider;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Framework\View\Element\UiComponentInterface;
use Magento\Ui\Component\Listing\Columns\Column;
use MagoAssistant\Mago\Test\Integration\MagentoObjectManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #310: the conversations grid filters, sorts, searches and pages in SQL, through the same
 * DataProvider and collection the listing uses
 */
final class GridTest extends TestCase
{
    private const ADMIN_USER_ID = 555201;
    private const ADMIN_USERNAME = 'mago-grid-test-555201';
    private const LISTING = 'mago_conversations_listing';

    private ResourceConnection $resourceConnection;
    private DataProvider $dataProvider;
    private FilterBuilder $filterBuilder;
    private ConversationFixture $fixture;

    /** @var array<string, int> conversation id by title */
    private array $conversations = [];

    protected function setUp(): void
    {
        $objectManager = MagentoObjectManager::get();
        $this->resourceConnection = $objectManager->get(ResourceConnection::class);
        $this->filterBuilder = $objectManager->get(FilterBuilder::class);
        $this->dataProvider = $objectManager->create(DataProvider::class, [
            'name' => 'mago_conversations_listing_data_source',
            'primaryFieldName' => 'entity_id',
            'requestFieldName' => 'id',
        ]);
        $this->fixture = new ConversationFixture($this->resourceConnection, self::ADMIN_USER_ID, self::ADMIN_USERNAME);
        $this->fixture->removeEverything();
        $this->fixture->createAdmin();

        $this->conversations = [
            'Orders on hold last week' => $this->fixture->createConversation('Orders on hold last week', 1, 100),
            'Flush the full page cache' => $this->fixture->createConversation('Flush the full page cache', 5, 3000),
            'Bestselling products in March' => $this->fixture->createConversation('Bestselling products in March', 3, 900),
        ];
        $this->addFilter('admin_user_id', (string)self::ADMIN_USER_ID, 'eq');
    }

    protected function tearDown(): void
    {
        $this->fixture->removeEverything();
        MagentoObjectManager::get()->get(RequestInterface::class)->setParams(['sorting' => null]);
    }

    #[Test]
    public function itSortsOnAComputedColumn(): void
    {
        $this->dataProvider->addOrder('message_count', 'DESC');

        $titles = $this->titles();

        self::assertSame(
            ['Flush the full page cache', 'Bestselling products in March', 'Orders on hold last week'],
            $titles
        );
    }

    #[Test]
    public function itFiltersOnARangeOfAComputedColumn(): void
    {
        $this->addFilter('total_tokens', '500', 'gteq');
        $this->addFilter('total_tokens', '1000', 'lteq');

        $titles = $this->titles();

        self::assertSame(['Bestselling products in March'], $titles);
    }

    #[Test]
    public function itFiltersATextColumn(): void
    {
        $this->addFilter('title', '%cache%', 'like');

        $titles = $this->titles();

        self::assertSame(['Flush the full page cache'], $titles);
    }

    #[Test]
    public function itFindsAConversationByKeyword(): void
    {
        $this->addFilter('fulltext', 'bestselling', 'fulltext');

        $titles = $this->titles();

        self::assertSame(['Bestselling products in March'], $titles);
    }

    #[Test]
    public function itReturnsOnePageButCountsEveryMatch(): void
    {
        $this->dataProvider->addOrder('entity_id', 'ASC');
        $this->dataProvider->setLimit(2, 1);

        $data = $this->dataProvider->getData();

        self::assertSame(3, $data['totalRecords']);
        self::assertSame(['Flush the full page cache'], array_column($data['items'], 'title'));
    }

    #[Test]
    public function itShowsTheUsageColumnsOfAConversation(): void
    {
        $this->addFilter('title', 'Flush the full page cache', 'eq');

        $row = $this->dataProvider->getData()['items'][0];

        self::assertSame('5', (string)$row['message_count']);
        self::assertSame('3000', (string)$row['total_tokens']);
        self::assertSame('claude-test', $row['model']);
        self::assertSame('cache_manager', $row['skills_used']);
        self::assertSame(0.0126, (float)$row['estimated_cost']);
    }

    #[Test]
    public function itFiltersOnTheJoinedAdminName(): void
    {
        $this->addFilter('admin_username', '%grid-test-555201%', 'like');

        $titles = $this->titles();

        self::assertEqualsCanonicalizing(array_keys($this->conversations), $titles);
    }

    #[Test]
    public function itFindsNothingForAnAdminNameThatDoesNotMatch(): void
    {
        $this->addFilter('admin_username', '%nobody-by-this-name%', 'like');

        $titles = $this->titles();

        self::assertSame([], $titles);
    }

    #[Test]
    public function itListsTheMostRecentlyUpdatedConversationFirstByDefault(): void
    {
        $this->setUpdatedAt('Orders on hold last week', '2021-01-01 00:00:00');
        $this->setUpdatedAt('Bestselling products in March', '2020-01-01 00:00:00');
        $this->sortTheWayTheGridDoesBeforeTheAdminPicksAColumn();
        $this->dataProvider = $this->preparedListing()->getContext()->getDataProvider();
        $this->addFilter('admin_user_id', (string)self::ADMIN_USER_ID, 'eq');

        $titles = $this->titles();

        self::assertSame(
            ['Flush the full page cache', 'Orders on hold last week', 'Bestselling products in March'],
            $titles
        );
    }

    /**
     * @return string[]
     */
    private function titles(): array
    {
        return array_column($this->dataProvider->getData()['items'], 'title');
    }

    private function addFilter(string $field, string $value, string $conditionType): void
    {
        $this->dataProvider->addFilter(
            $this->filterBuilder->setField($field)->setValue($value)->setConditionType($conditionType)->create()
        );
    }

    private function setUpdatedAt(string $title, string $updatedAt): void
    {
        $this->resourceConnection->getConnection()->update(
            $this->resourceConnection->getTableName('mago_conversation'),
            ['updated_at' => $updatedAt],
            ['entity_id = ?' => $this->conversations[$title]]
        );
    }

    /**
     * The grid's JavaScript sends the sorting of the one column the listing XML gives a default
     * direction, as the request's "sorting" parameter
     */
    private function sortTheWayTheGridDoesBeforeTheAdminPicksAColumn(): void
    {
        $sortedColumns = array_values(array_filter(
            $this->columnsOf($this->preparedListing()),
            static fn (Column $column): bool => (string)$column->getData('config/sorting') !== ''
        ));
        self::assertCount(1, $sortedColumns, 'The listing should declare a default sort order on exactly one column.');

        MagentoObjectManager::get()->get(RequestInterface::class)->setParams(['sorting' => [
            'field' => $sortedColumns[0]->getName(),
            'direction' => $sortedColumns[0]->getData('config/sorting'),
        ]]);
    }

    /**
     * @return Column[]
     */
    private function columnsOf(UiComponentInterface $component): array
    {
        return array_merge(
            $component instanceof Column ? [$component] : [],
            ...array_map($this->columnsOf(...), array_values($component->getChildComponents()))
        );
    }

    /**
     * The listing as the grid builds it, so its columns apply the request's sort order
     */
    private function preparedListing(): UiComponentInterface
    {
        $listing = MagentoObjectManager::get()->get(UiComponentFactory::class)->create(self::LISTING);
        $this->prepare($listing);

        return $listing;
    }

    private function prepare(UiComponentInterface $component): void
    {
        foreach ($component->getChildComponents() as $child) {
            $this->prepare($child);
        }
        $component->prepare();
    }
}

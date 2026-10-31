<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Integration\Controller\Adminhtml\Conversations;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Ui\Component\MassAction\Filter;
use MagoAssistant\Mago\Controller\Adminhtml\Conversations\MassDelete;
use MagoAssistant\Mago\Test\Integration\MagentoObjectManager;
use MagoAssistant\Mago\Test\Integration\Model\ResourceModel\Conversation\ConversationFixture;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Issue #310: "Select All" in the conversations grid deletes what the active filters show, not
 * every conversation and not only the rows on the current page
 */
final class MassDeleteTest extends TestCase
{
    private const ADMIN_USER_ID = 555202;
    private const ADMIN_USERNAME = 'mago-mass-delete-test-555202';
    private const OTHER_ADMIN_USER_ID = 555203;
    private const OTHER_ADMIN_USERNAME = 'mago-mass-delete-other-555203';
    private const LISTING = 'mago_conversations_listing';

    private RequestInterface $request;
    private ConversationFixture $fixture;
    private ConversationFixture $otherAdminFixture;

    protected function setUp(): void
    {
        $objectManager = MagentoObjectManager::get();
        $this->request = $objectManager->get(RequestInterface::class);
        $this->fixture = new ConversationFixture(
            $objectManager->get(ResourceConnection::class),
            self::ADMIN_USER_ID,
            self::ADMIN_USERNAME
        );
        $this->otherAdminFixture = new ConversationFixture(
            $objectManager->get(ResourceConnection::class),
            self::OTHER_ADMIN_USER_ID,
            self::OTHER_ADMIN_USERNAME
        );
        $this->fixture->removeEverything();
        $this->otherAdminFixture->removeEverything();
        $this->fixture->createAdmin();
        $this->otherAdminFixture->createAdmin();

        $this->fixture->createConversation('Orders on hold last week', 1, 100);
        $this->fixture->createConversation('Flush the full page cache', 5, 3000);
        $this->fixture->createConversation('Clean the image cache', 2, 400);
        $this->otherAdminFixture->createConversation('Flush the full page cache', 1, 100);
    }

    protected function tearDown(): void
    {
        $this->fixture->removeEverything();
        $this->otherAdminFixture->removeEverything();
        $this->request->setParams(['namespace' => null, 'excluded' => null, 'selected' => null, 'filters' => null]);
    }

    #[Test]
    public function itDeletesEveryConversationTheActiveFiltersShowWhenAllAreSelected(): void
    {
        $this->request->setParams([
            'namespace' => self::LISTING,
            'excluded' => 'false',
            'filters' => ['admin_username' => self::ADMIN_USERNAME, 'title' => 'cache'],
        ]);

        $this->massDelete()->execute();

        self::assertSame(['Orders on hold last week'], $this->fixture->titlesOfRemainingConversations());
        self::assertSame(1, $this->fixture->countUsageRows());
    }

    #[Test]
    public function itLeavesTheConversationsOutsideTheActiveFiltersAlone(): void
    {
        $this->request->setParams([
            'namespace' => self::LISTING,
            'excluded' => 'false',
            'filters' => ['admin_username' => self::ADMIN_USERNAME, 'title' => 'cache'],
        ]);

        $this->massDelete()->execute();

        self::assertSame(['Flush the full page cache'], $this->otherAdminFixture->titlesOfRemainingConversations());
    }

    #[Test]
    public function itDeletesOnlyTheTickedConversations(): void
    {
        $this->request->setParams([
            'namespace' => self::LISTING,
            'selected' => [(string)$this->idOf('Clean the image cache')],
            'filters' => ['admin_username' => self::ADMIN_USERNAME],
        ]);

        $this->massDelete()->execute();

        self::assertSame(
            ['Orders on hold last week', 'Flush the full page cache'],
            $this->fixture->titlesOfRemainingConversations()
        );
    }

    #[Test]
    public function itKeepsTheConversationsExcludedFromASelectAll(): void
    {
        $this->request->setParams([
            'namespace' => self::LISTING,
            'excluded' => [(string)$this->idOf('Clean the image cache')],
            'filters' => ['admin_username' => self::ADMIN_USERNAME, 'title' => 'cache'],
        ]);

        $this->massDelete()->execute();

        self::assertSame(
            ['Orders on hold last week', 'Clean the image cache'],
            $this->fixture->titlesOfRemainingConversations()
        );
    }

    /**
     * A fresh Filter for every request, as Magento has: the shared one keeps the listing it built first
     */
    private function massDelete(): MassDelete
    {
        $objectManager = MagentoObjectManager::get();

        return $objectManager->create(MassDelete::class, ['filter' => $objectManager->create(Filter::class)]);
    }

    private function idOf(string $title): int
    {
        $connection = MagentoObjectManager::get()->get(ResourceConnection::class)->getConnection();

        return (int)$connection->fetchOne(
            $connection->select()
                ->from($connection->getTableName('mago_conversation'), 'entity_id')
                ->where('admin_user_id = ?', self::ADMIN_USER_ID)
                ->where('title = ?', $title)
        );
    }
}

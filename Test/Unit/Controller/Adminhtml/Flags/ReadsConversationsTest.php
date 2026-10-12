<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Controller\Adminhtml\Flags;

use MagoAssistant\Mago\Controller\Adminhtml\Flags\Delete;
use MagoAssistant\Mago\Controller\Adminhtml\Flags\Export;
use MagoAssistant\Mago\Controller\Adminhtml\Flags\Index;
use MagoAssistant\Mago\Controller\Adminhtml\Flags\MassDelete;
use MagoAssistant\Mago\Controller\Adminhtml\Flags\ReadsConversations;
use MagoAssistant\Mago\Controller\Adminhtml\Flags\Resolve;
use MagoAssistant\Mago\Controller\Adminhtml\Flags\View;
use MagoAssistant\Mago\Test\Unit\Fakes\FakeAclAuthorization;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReadsConversationsTest extends TestCase
{
    private const FLAGS = 'MagoAssistant_Mago::flags';
    private const CONVERSATIONS = 'MagoAssistant_Mago::conversations';

    #[Test]
    public function itAllowsAnAdminWithBothTheFlagsAndTheConversationsGrant(): void
    {
        $action = new FlagsActionDouble(new FakeAclAuthorization([self::FLAGS, self::CONVERSATIONS]));

        $isAllowed = $action->isAllowed();

        self::assertTrue($isAllowed);
    }

    #[Test]
    public function itRefusesAnAdminWhoMayNotReadOtherAdminsConversations(): void
    {
        $action = new FlagsActionDouble(new FakeAclAuthorization([self::FLAGS]));

        $isAllowed = $action->isAllowed();

        self::assertFalse($isAllowed);
    }

    #[Test]
    public function itRefusesAnAdminWithoutTheFlagsGrant(): void
    {
        $action = new FlagsActionDouble(new FakeAclAuthorization([self::CONVERSATIONS]));

        $isAllowed = $action->isAllowed();

        self::assertFalse($isAllowed);
    }

    /**
     * Every Answer Feedback action shows or changes a copy of someone else's conversation, so
     * dropping the trait from one of them would open it up to the flags grant alone.
     */
    #[Test]
    #[DataProvider('flagsActions')]
    public function itGuardsEveryFlaggedAnswersAction(string $actionClass): void
    {
        $traits = class_uses($actionClass);

        self::assertContains(ReadsConversations::class, $traits);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function flagsActions(): array
    {
        return [
            'index' => [Index::class],
            'view' => [View::class],
            'export' => [Export::class],
            'resolve' => [Resolve::class],
            'delete' => [Delete::class],
            'mass delete' => [MassDelete::class],
        ];
    }
}

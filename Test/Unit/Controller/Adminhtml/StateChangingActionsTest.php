<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Controller\Adminhtml;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use MagoAssistant\Mago\Controller\Adminhtml\Chat\Confirm;
use MagoAssistant\Mago\Controller\Adminhtml\Chat\Delete as ChatDelete;
use MagoAssistant\Mago\Controller\Adminhtml\Chat\Flag;
use MagoAssistant\Mago\Controller\Adminhtml\Chat\Reject;
use MagoAssistant\Mago\Controller\Adminhtml\Conversations\Delete as ConversationDelete;
use MagoAssistant\Mago\Controller\Adminhtml\Conversations\MassDelete as ConversationMassDelete;
use MagoAssistant\Mago\Controller\Adminhtml\Flags\Delete as FlagDelete;
use MagoAssistant\Mago\Controller\Adminhtml\Flags\MassDelete as FlagMassDelete;
use MagoAssistant\Mago\Controller\Adminhtml\Flags\Resolve;
use MagoAssistant\Mago\Controller\Adminhtml\Skills\SavePermissions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An action that changes or deletes data only answers POST, so Magento checks the form key on it.
 * Reached by GET it would hang on the admin URL secret key alone, and with "Add Secret Key to URLs"
 * switched off an image tag on any page an admin opens would run it.
 */
final class StateChangingActionsTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string}>
     */
    public static function stateChangingActions(): array
    {
        return [
            'delete a conversation' => [ConversationDelete::class],
            'delete conversations in bulk' => [ConversationMassDelete::class],
            'delete a conversation from the panel' => [ChatDelete::class],
            'confirm a write' => [Confirm::class],
            'reject a write' => [Reject::class],
            'flag an answer' => [Flag::class],
            'delete a flag' => [FlagDelete::class],
            'delete flags in bulk' => [FlagMassDelete::class],
            'resolve a flag' => [Resolve::class],
            'save skill permissions' => [SavePermissions::class],
        ];
    }

    /**
     * @param class-string $action
     */
    #[Test]
    #[DataProvider('stateChangingActions')]
    public function itOnlyAnswersPost(string $action): void
    {
        $interfaces = class_implements($action);

        self::assertContains(HttpPostActionInterface::class, $interfaces);
        self::assertNotContains(HttpGetActionInterface::class, $interfaces);
    }
}

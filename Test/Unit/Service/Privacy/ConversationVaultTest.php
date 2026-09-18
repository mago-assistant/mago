<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Privacy;

use MagoAssistant\Mago\Api\Privacy\VaultStorageInterface;
use MagoAssistant\Mago\Service\Privacy\ConversationVault;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ConversationVaultTest extends TestCase
{
    private function inMemoryStorage(): VaultStorageInterface
    {
        return new class implements VaultStorageInterface {
            /** @var array<int,array<int,array{token:string,value:string,type:string}>> */
            public array $rows = [];

            public function loadForConversation(int $conversationId): array
            {
                return $this->rows[$conversationId] ?? [];
            }

            public function persist(int $conversationId, string $token, string $value, string $type): void
            {
                $this->rows[$conversationId][] = ['token' => $token, 'value' => $value, 'type' => $type];
            }
        };
    }

    #[Test]
    public function bindingAnotherConversationLeavesTheFirstOnesTokensBehind(): void
    {
        $storage = $this->inMemoryStorage();
        $vault = new ConversationVault($storage);

        $vault->beginConversation(1);
        $first = $vault->tokenise('one@example.com', 'email');

        $vault->beginConversation(2);

        self::assertFalse($vault->has($first), 'a token from another conversation must not resolve');
        self::assertSame('I mailed ' . $first, $vault->rehydrate('I mailed ' . $first));
    }

    #[Test]
    public function eachConversationNumbersItsTokensFromItsOwnMap(): void
    {
        $storage = $this->inMemoryStorage();
        $vault = new ConversationVault($storage);

        $vault->beginConversation(1);
        $vault->tokenise('one@example.com', 'email');

        $vault->beginConversation(2);

        self::assertSame('[email_1]', $vault->tokenise('two@example.com', 'email'));
        self::assertSame('two@example.com', $vault->rehydrate('[email_1]'));
    }

    #[Test]
    public function withoutStorageItStaysRequestScopedInMemory(): void
    {
        $vault = new ConversationVault();

        self::assertSame('[email_1]', $vault->tokenise('jan@example.com', 'email'));
        self::assertSame('I mailed jan@example.com', $vault->rehydrate('I mailed [email_1]'));
    }

    #[Test]
    public function aTokenMintedInOneRequestResolvesInTheNext(): void
    {
        $storage = $this->inMemoryStorage();

        $turnOne = new ConversationVault($storage);
        $turnOne->beginConversation(7);
        $token = $turnOne->tokenise('jan@example.com', 'email');

        // A fresh request (new vault instance) binds the same conversation and must resolve the token.
        $turnTwo = new ConversationVault($storage);
        $turnTwo->beginConversation(7);

        self::assertSame($token, $turnTwo->tokenise('jan@example.com', 'email'));
        self::assertSame('reply jan@example.com', $turnTwo->rehydrate('reply ' . $token));
    }

    #[Test]
    public function itContinuesNumberingAfterReloadingInsteadOfColliding(): void
    {
        $storage = $this->inMemoryStorage();

        $turnOne = new ConversationVault($storage);
        $turnOne->beginConversation(7);
        $turnOne->tokenise('a@example.com', 'email'); // [email_1]

        $turnTwo = new ConversationVault($storage);
        $turnTwo->beginConversation(7);

        self::assertSame('[email_2]', $turnTwo->tokenise('b@example.com', 'email'));
    }

    #[Test]
    public function differentConversationsDoNotSeeEachOthersTokens(): void
    {
        $storage = $this->inMemoryStorage();

        $a = new ConversationVault($storage);
        $a->beginConversation(1);
        $a->tokenise('a@example.com', 'email');

        $b = new ConversationVault($storage);
        $b->beginConversation(2);

        self::assertSame('[email_1]', $b->tokenise('b@example.com', 'email'));
        self::assertFalse($b->has('[email_1]') && $b->rehydrate('[email_1]') === 'a@example.com');
    }
}

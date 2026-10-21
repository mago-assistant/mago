<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Privacy;

use MagoAssistant\Mago\Api\Privacy\VaultStorageInterface;
use MagoAssistant\Mago\Service\Privacy\ConversationVault;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function declaredTokenTypes(): array
    {
        return [
            'underscore' => ['tax_id', 'mago://taxid_1'],
            'capital' => ['vatNumber', 'mago://vatnumber_1'],
            'digit' => ['line2', 'mago://line_1'],
            'nothing left' => ['_1', 'mago://value_1'],
        ];
    }

    #[Test]
    #[DataProvider('declaredTokenTypes')]
    public function itFoldsATokenTypeToLowercaseLetters(string $type, string $expectedToken): void
    {
        $vault = new ConversationVault();

        $token = $vault->tokenise('NL123456789B01', $type);

        self::assertSame($expectedToken, $token);
        self::assertSame('NL123456789B01', $vault->rehydrate($token));
    }

    #[Test]
    public function aStoredTokenWithPunctuationInItsTypeStillResolves(): void
    {
        $storage = $this->inMemoryStorage();
        $storage->rows[7] = [['token' => 'mago://vat-number_1', 'value' => 'NL123456789B01', 'type' => 'vat-number']];
        $vault = new ConversationVault($storage);
        $vault->beginConversation(7);

        self::assertSame('"NL123456789B01", ok', $vault->rehydrate('"mago://vat-number_1", ok'));
    }

    #[Test]
    public function aTokenStoredBeforeTypesWereFoldedStillResolves(): void
    {
        $storage = $this->inMemoryStorage();
        $storage->rows[7] = [['token' => 'mago://tax_id_3', 'value' => 'NL123456789B01', 'type' => 'tax_id']];
        $vault = new ConversationVault($storage);
        $vault->beginConversation(7);

        self::assertSame('VAT NL123456789B01', $vault->rehydrate('VAT mago://tax_id_3'));
    }

    #[Test]
    public function anUnknownTokenIsNotReadAsTheKnownTokenItStartsWith(): void
    {
        $vault = new ConversationVault();
        $token = $vault->tokenise('jan@example.com', 'email');

        self::assertSame('mail ' . $token . '0 and jan@example.com', $vault->rehydrate('mail ' . $token . '0 and ' . $token));
    }

    #[Test]
    public function aHeldTokenThatWasShownIsPersistedAndResolves(): void
    {
        $storage = $this->inMemoryStorage();
        $vault = new ConversationVault($storage);
        $vault->beginConversation(7);
        $vault->holdNewTokens();
        $token = $vault->tokenise('jan@example.com', 'email');

        $vault->releaseHeldTokens('{"email":"' . $token . '"}');

        self::assertSame('jan@example.com', $vault->valueOf($token));
        self::assertSame([['token' => $token, 'value' => 'jan@example.com', 'type' => 'email']], $storage->rows[7]);
    }

    #[Test]
    public function aHeldTokenThatWasNeverShownIsForgottenAndNeverStored(): void
    {
        $storage = $this->inMemoryStorage();
        $vault = new ConversationVault($storage);
        $vault->beginConversation(7);
        $vault->holdNewTokens();
        $shown = $vault->tokenise('jan@example.com', 'email');
        $cutOff = $vault->tokenise('piet@example.com', 'email');

        $vault->releaseHeldTokens('{"emails":["' . $shown . '"');

        self::assertNull($vault->valueOf($cutOff));
        self::assertSame([$shown], array_column($storage->rows[7], 'token'));
    }

    #[Test]
    public function aLongerTokenInTheShownTextDoesNotKeepAShorterOne(): void
    {
        $vault = new ConversationVault();
        $vault->holdNewTokens();
        $first = $vault->tokenise('jan@example.com', 'email');

        $vault->releaseHeldTokens('mago://email_12');

        self::assertNull($vault->valueOf($first));
    }

    #[Test]
    public function aForgottenNumberIsNeverHandedToAnotherValue(): void
    {
        $vault = new ConversationVault();
        $vault->holdNewTokens();
        $forgotten = $vault->tokenise('jan@example.com', 'email');
        $vault->releaseHeldTokens('');

        $next = $vault->tokenise('piet@example.com', 'email');

        self::assertNotSame($forgotten, $next);
        self::assertSame('mago://email_2', $next);
    }

    #[Test]
    public function aTokenMintedAfterTheReleaseIsStoredRightAway(): void
    {
        $storage = $this->inMemoryStorage();
        $vault = new ConversationVault($storage);
        $vault->beginConversation(7);
        $vault->holdNewTokens();
        $vault->releaseHeldTokens('');

        $token = $vault->tokenise('jan@example.com', 'email');

        self::assertSame([$token], array_column($storage->rows[7], 'token'));
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

        self::assertSame('mago://email_1', $vault->tokenise('two@example.com', 'email'));
        self::assertSame('two@example.com', $vault->rehydrate('mago://email_1'));
    }

    #[Test]
    public function withoutStorageItStaysRequestScopedInMemory(): void
    {
        $vault = new ConversationVault();

        self::assertSame('mago://email_1', $vault->tokenise('jan@example.com', 'email'));
        self::assertSame('I mailed jan@example.com', $vault->rehydrate('I mailed mago://email_1'));
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
        $turnOne->tokenise('a@example.com', 'email'); // mago://email_1

        $turnTwo = new ConversationVault($storage);
        $turnTwo->beginConversation(7);

        self::assertSame('mago://email_2', $turnTwo->tokenise('b@example.com', 'email'));
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

        self::assertSame('mago://email_1', $b->tokenise('b@example.com', 'email'));
        self::assertFalse($b->has('mago://email_1') && $b->rehydrate('mago://email_1') === 'a@example.com');
    }
}

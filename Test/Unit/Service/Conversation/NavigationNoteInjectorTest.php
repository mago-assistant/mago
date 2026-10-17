<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Conversation;

use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Service\Conversation\NavigationNoteInjector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NavigationNoteInjectorTest extends TestCase
{
    /** @var NavigationNoteInjector */
    private NavigationNoteInjector $injector;

    protected function setUp(): void
    {
        $this->injector = new NavigationNoteInjector(new Json());
    }

    #[Test]
    public function theFirstMessageSentFromAFormIsToldWhichPageItIs(): void
    {
        $messages = $this->injector->annotate([$this->user('Rename it', $this->product(42))]);

        $this->assertStringStartsWith(
            '[Page context: the administrator is viewing product #42',
            $messages[0]['content']
        );
        $this->assertStringEndsWith("\n\nRename it", $messages[0]['content']);
    }

    #[Test]
    public function theFirstMessageSentFromNoFormIsLeftAlone(): void
    {
        $messages = $this->injector->annotate([$this->user('Hello', null)]);

        $this->assertSame('Hello', $messages[0]['content']);
    }

    #[Test]
    public function aLaterMessageFromTheSamePageIsLeftAlone(): void
    {
        $messages = $this->injector->annotate([
            $this->user('One', $this->product(42)),
            $this->assistant('Done'),
            $this->user('Two', $this->product(42)),
        ]);

        $this->assertSame('Two', $messages[2]['content']);
    }

    #[Test]
    public function aMessageFromAnotherPageGetsAContextUpdate(): void
    {
        $messages = $this->injector->annotate([
            $this->user('One', $this->product(42)),
            $this->user('Two', $this->product(43)),
        ]);

        $this->assertStringStartsWith(
            '[Context update: the administrator navigated from product #42',
            $messages[1]['content']
        );
    }

    #[Test]
    public function leavingTheFormIsNoted(): void
    {
        $messages = $this->injector->annotate([
            $this->user('One', $this->product(42)),
            $this->user('Two', null),
        ]);

        $this->assertStringContainsString('no form open', $messages[1]['content']);
    }

    /**
     * What keeps a provider's prompt cache valid: replaying the same stored history must produce
     * the same text, and a new message must not change what the earlier ones say.
     */
    #[Test]
    public function earlierMessagesReadTheSameWhenALaterOneIsAdded(): void
    {
        $history = [
            $this->user('One', $this->product(42)),
            $this->assistant('Done'),
            $this->user('Two', $this->product(43)),
        ];

        $before = $this->injector->annotate($history);
        $after = $this->injector->annotate(array_merge($history, [
            $this->assistant('Done too'),
            $this->user('Three', $this->product(44)),
        ]));

        $this->assertSame($before, array_slice($after, 0, 3));
    }

    #[Test]
    public function assistantAndToolMessagesAreNeverTouched(): void
    {
        $messages = $this->injector->annotate([
            $this->user('One', $this->product(42)),
            $this->assistant('Reply'),
        ]);

        $this->assertSame('Reply', $messages[1]['content']);
    }

    /**
     * @return array<string,mixed>
     */
    private function product(int $id): array
    {
        return [
            'route' => '/admin/catalog/product/edit/id/' . $id . '/',
            'namespace' => 'product_form',
            'entity_type' => 'product',
            'entity_id' => (string)$id,
            'is_new_entity' => false,
            'store_id' => null,
        ];
    }

    /**
     * @param array<string,mixed>|null $location
     * @return array<string,mixed>
     */
    private function user(string $content, ?array $location): array
    {
        return [
            'role' => 'user',
            'content' => $content,
            'page_context' => $location === null ? null : json_encode($location),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function assistant(string $content): array
    {
        return ['role' => 'assistant', 'content' => $content, 'page_context' => null];
    }
}

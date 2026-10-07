<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Conversation;

use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Model\Form\PageLocation;

/**
 * A conversation outlives the admin page it started on: the administrator asks about one
 * category, saves, opens another and keeps chatting in the same thread. Every earlier turn,
 * including the form reads and writes, is replayed to the provider on each request, and only a
 * single line in the system prompt says which page is open now. Left like that, the model has to
 * reconcile stale tool results with that one line and tends to do so out loud.
 *
 * This walks the stored messages in order and, wherever a user message was sent from a
 * different page than the previous one, prefixes the copy that goes to the provider with a
 * short note saying so. The stored message itself is never changed.
 *
 * The first message of a conversation sent from a form page gets a note too, saying which page it
 * is. That note used to be a line in the system prompt, rebuilt for every request, which changed
 * the very start of the prompt whenever the administrator moved between pages and so gave a
 * provider that caches a prompt prefix nothing stable to reuse. Here the note is a pure function
 * of what is stored per message, so every request replays the same text for the same history, and
 * only the message that was sent from a new page differs.
 */
class NavigationNoteInjector
{
    private const ROLE_USER = 'user';
    private const COLUMN_PAGE_CONTEXT = 'page_context';
    private const COLUMN_CONTENT = 'content';
    private const COLUMN_ROLE = 'role';

    public function __construct(
        private readonly Json $json
    ) {
    }

    /**
     * @param array<int,array<string,mixed>> $messages Stored message rows, oldest first
     * @return array<int,array<string,mixed>>
     */
    public function annotate(array $messages): array
    {
        $previousLocation = null;
        $hasSeenUserMessage = false;

        foreach ($messages as $index => $message) {
            if (($message[self::COLUMN_ROLE] ?? '') !== self::ROLE_USER) {
                continue;
            }

            $location = $this->locationOf($message);

            $note = $this->noteForMessage($hasSeenUserMessage, $previousLocation, $location);
            if ($note !== null) {
                $messages[$index][self::COLUMN_CONTENT] = $note
                    . "\n\n" . (string)($message[self::COLUMN_CONTENT] ?? '');
            }

            $previousLocation = $location;
            $hasSeenUserMessage = true;
        }

        return $messages;
    }

    private function noteForMessage(bool $hasSeenUserMessage, ?PageLocation $previous, ?PageLocation $current): ?string
    {
        if (!$hasSeenUserMessage) {
            return $current === null ? null : $this->openingNote($current);
        }

        return $this->isSameLocation($previous, $current) ? null : $this->noteFor($previous, $current);
    }

    private function openingNote(PageLocation $current): string
    {
        return sprintf(
            '[Page context: the administrator is viewing %s and has its form open.]',
            $current->describe()
        );
    }

    private function isSameLocation(?PageLocation $previous, ?PageLocation $current): bool
    {
        if ($previous === null || $current === null) {
            return $previous === $current;
        }

        return $previous->equals($current);
    }

    /**
     * @param array<string,mixed> $message
     */
    private function locationOf(array $message): ?PageLocation
    {
        $stored = $message[self::COLUMN_PAGE_CONTEXT] ?? null;

        if (!is_string($stored) || $stored === '') {
            return null;
        }

        try {
            $decoded = $this->json->unserialize($stored);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return is_array($decoded) ? PageLocation::fromArray($decoded) : null;
    }

    private function noteFor(?PageLocation $previous, ?PageLocation $current): string
    {
        return sprintf('[Context update: %s %s]', $this->movement($previous, $current), $this->reminder($current));
    }

    private function movement(?PageLocation $previous, ?PageLocation $current): string
    {
        if ($previous === null && $current !== null) {
            return sprintf('the administrator has since opened %s.', $current->describe());
        }

        if ($previous !== null && $current === null) {
            return sprintf('the administrator has left %s and currently has no form open.', $previous->describe());
        }

        return sprintf(
            'the administrator navigated from %s to %s.',
            $previous?->describe() ?? '',
            $current?->describe() ?? ''
        );
    }

    private function reminder(?PageLocation $current): string
    {
        if ($current === null) {
            return 'Earlier form reads and writes in this conversation concerned a page that is no longer open.';
        }

        return 'Earlier form reads and writes in this conversation concerned the previous page, not this one; '
            . 'use the page_form tool before describing or changing the current form.';
    }
}

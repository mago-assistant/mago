<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Api\Privacy;

/**
 * Persists the per-conversation token vault (issue #97) so a token minted on one turn still resolves
 * on the next, and a confirmed write (a separate request) sees the same map. Implementations must
 * degrade gracefully: if the store is unavailable the vault falls back to request-scoped memory
 * rather than breaking the chat.
 *
 * @api
 */
interface VaultStorageInterface
{
    /**
     * @return array<int,array{token:string,value:string,type:string}>
     */
    public function loadForConversation(int $conversationId): array;

    public function persist(int $conversationId, string $token, string $value, string $type): void;
}

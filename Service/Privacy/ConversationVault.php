<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Privacy;

use MagoAssistant\Mago\Api\Privacy\VaultStorageInterface;

/**
 * The reversible token map for one conversation (issue #97): a PII value becomes a stable,
 * type-carrying token towards the LLM and is swapped back for the admin. The same value always
 * yields the same token within the conversation, so a token from an earlier turn still resolves.
 *
 * A bound VaultStorageInterface (beginConversation) makes the map survive across requests, so a
 * token minted last turn or before a confirmed write still resolves. Without storage it is
 * request-scoped memory, and a storage failure silently degrades to that.
 */
class ConversationVault
{
    /**
     * Anything shaped like a token: the scheme form it is minted in, or the bracket form older rows
     * still hold, with any type a stored row may carry (rows from before types were folded to
     * lowercase letters hold mago://tax_id_3 or mago://vat-number_1). The number is read whole, so
     * an unknown mago://email_60 is never taken for a known mago://email_6. Only tokens the vault
     * knows are replaced; everything else stays as written.
     */
    private const TOKEN_PATTERN = '/mago:\/\/[^\s"\'<>\[\]]+?_\d+(?!\d)|\[[^\s"\'<>\[\]]+?_\d+\]/';

    /** @var array<string,string> "type\0value" => token */
    private array $tokenByValue = [];

    /** @var array<string,string> token => original value */
    private array $valueByToken = [];

    /** @var array<string,int> type => highest issued number */
    private array $counters = [];

    /** @var array<string,string>|null Conceal map (value => token), rebuilt lazily after a mint */
    private ?array $concealMap = null;

    private ?int $conversationId = null;

    /** @var array<string,array{0:string,1:string}>|null token => [value, type] minted while held */
    private ?array $heldTokens = null;

    public function __construct(
        private readonly ?VaultStorageInterface $storage = null
    ) {
    }

    /**
     * Bind the vault to a conversation and load its existing tokens, so numbering continues and
     * earlier turns' tokens resolve. Safe to call once per request; storage errors are swallowed.
     */
    public function beginConversation(int $conversationId): void
    {
        if ($this->conversationId !== null && $this->conversationId !== $conversationId) {
            $this->tokenByValue = [];
            $this->valueByToken = [];
            $this->counters = [];
        }

        $this->conversationId = $conversationId;
        if ($this->storage === null) {
            return;
        }

        foreach ($this->storage->loadForConversation($conversationId) as $row) {
            $this->remember($row['type'], $row['value'], $row['token']);
        }
    }

    public function tokenise(string $value, string $type): string
    {
        $type = PiiClass::tokenType($type);
        if ($value === '') {
            return $value;
        }

        $key = $type . "\0" . $value;
        if (isset($this->tokenByValue[$key])) {
            return $this->tokenByValue[$key];
        }

        $number = ($this->counters[$type] ?? 0) + 1;
        // Machine syntax, deliberately. A bracketed token reads as a placeholder: asked for a
        // Dutch answer the model rewrote [name_1] as [naam], and inside a markdown link it dropped
        // the brackets altogether. A scheme-shaped token survives both, because it does not look
        // like anything the model is supposed to fill in or translate.
        $token = sprintf('mago://%s_%d', $type, $number);
        $this->remember($type, $value, $token);

        if ($this->heldTokens !== null) {
            $this->heldTokens[$token] = [$value, $type];
            return $token;
        }
        $this->persist($token, $value, $type);

        return $token;
    }

    /**
     * Keep the tokens minted from now on in memory only, until releaseHeldTokens(). A tool result
     * is tokenised whole before the response cap cuts it, and a token for a row past the cut is a
     * value the model never saw that would still resolve: numbering is sequential, so the model
     * (or text it read) can name mago://email_40 without being shown it.
     */
    public function holdNewTokens(): void
    {
        $this->heldTokens = [];
    }

    /**
     * Persist the held tokens that occur in $shownText, what actually left for the model and the
     * panel, and forget the others so they never resolve. The counters stay ahead for the rest of
     * this request; a later request numbers on from what storage holds, which can reuse a forgotten
     * number. That is safe because a forgotten token never left the server.
     */
    public function releaseHeldTokens(string $shownText): void
    {
        $held = $this->heldTokens ?? [];
        $this->heldTokens = null;

        foreach ($held as $token => [$value, $type]) {
            if (preg_match('/' . preg_quote($token, '/') . '(?!\d)/', $shownText) === 1) {
                $this->persist($token, $value, $type);
                continue;
            }
            $this->forget($token, $value, $type);
        }
    }

    public function rehydrate(string $text): string
    {
        if ($this->valueByToken === []) {
            return $text;
        }

        // Whole tokens only: strtr() would read a known mago://email_6 inside an unknown
        // mago://email_60 and hand back the wrong person's value with a 0 after it.
        return (string)preg_replace_callback(
            self::TOKEN_PATTERN,
            fn (array $match): string => $this->valueByToken[$match[0]] ?? $match[0],
            $text
        );
    }

    public function has(string $token): bool
    {
        return isset($this->valueByToken[$token]);
    }

    /**
     * The value behind one token, or null when this conversation never issued it.
     */
    public function valueOf(string $token): ?string
    {
        return $this->valueByToken[$token] ?? null;
    }

    /**
     * Replace any vaulted value occurring in the text with its token. Closes the echo path the
     * heuristic cannot: a tool embedding a rehydrated argument in kept free text (a write ack
     * "Invoice created for order #000000549") has no PII signature to match, but the vault knows the
     * value. Values shorter than six characters are left alone, a bare "42" would false-positive on
     * unrelated numbers in public data.
     */
    public function concealKnownValues(string $text): string
    {
        if ($this->concealMap === null) {
            $this->concealMap = [];
            foreach ($this->valueByToken as $token => $value) {
                if (strlen($value) >= 6) {
                    $this->concealMap[$value] = $token;
                }
            }
        }

        return $this->concealMap === [] ? $text : strtr($text, $this->concealMap);
    }

    private function persist(string $token, string $value, string $type): void
    {
        if ($this->conversationId !== null && $this->storage !== null) {
            $this->storage->persist($this->conversationId, $token, $value, $type);
        }
    }

    private function forget(string $token, string $value, string $type): void
    {
        unset($this->tokenByValue[$type . "\0" . $value], $this->valueByToken[$token]);
        $this->concealMap = null;
    }

    /**
     * Register a token and its value, and keep the per-type counter ahead of any number already
     * issued, whether it was just minted or loaded from storage.
     */
    private function remember(string $type, string $value, string $token): void
    {
        $this->tokenByValue[$type . "\0" . $value] = $token;
        $this->valueByToken[$token] = $value;
        $this->concealMap = null;

        if (preg_match('/_(\d+)\]?$/', $token, $m) === 1) {
            $this->counters[$type] = max($this->counters[$type] ?? 0, (int)$m[1]);
        }
    }
}

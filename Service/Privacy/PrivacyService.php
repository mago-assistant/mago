<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Privacy;

/**
 * The privacy-mode entry point ChatService uses (issue #97). One request-scoped instance holds the
 * conversation vault, so a value tokenised anywhere in the request reads back as the same token.
 */
class PrivacyService
{
    public function __construct(
        private readonly PrivacyFilter $filter,
        private readonly ConversationVault $vault,
        private readonly PiiHeuristic $heuristic
    ) {
    }

    /**
     * Bind the vault to the current conversation so tokens persist and resolve across turns and the
     * confirmed-write round-trip. Called once at the start of a chat request.
     */
    public function beginConversation(int $conversationId): void
    {
        $this->vault->beginConversation($conversationId);
    }

    /**
     * Filter a tool result before it reaches the LLM. $classes is the executed tool's own
     * declaration (ToolInterface::getFieldClassification() for the invoked action).
     *
     * @param array<string,array{0:string,1?:string}> $classes
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    public function filterToolResult(array $classes, array $result): array
    {
        return $this->filter->filter($classes, $result);
    }

    /**
     * Scrub free text in the outbound messages before they reach the LLM: the typed message, replayed
     * history and the custom system prompt. This is the one place that catches PII the admin types,
     * which no field classification can. Idempotent, so already-filtered tool results are untouched.
     *
     * @param array<int,array<string,mixed>> $messages
     * @return array<int,array<string,mixed>>
     */
    public function scrubMessages(array $messages): array
    {
        foreach ($messages as $index => $message) {
            if (is_string($message['content'] ?? null) && $message['content'] !== '') {
                $messages[$index]['content'] = $this->heuristic->tokeniseFreeText($message['content'], $this->vault);
            }
        }

        return $messages;
    }

    /**
     * Scrub one piece of free text into the bound vault. Used where a single string is persisted or
     * egresses outside the message array (the typed message before storage, a conversation title), so
     * the stored copy is tokenised per #97 decision 1. Idempotent like scrubMessages.
     */
    public function scrubText(string $text): string
    {
        return $this->heuristic->tokeniseFreeText($text, $this->vault);
    }

    /**
     * A conversation title from already-scrubbed text: truncated without cutting a vault token in
     * half (a partial token can neither rehydrate nor be neutralised on display).
     */
    public function safeTitle(string $scrubbedText, int $length = 50): string
    {
        return (string)preg_replace('/\[[a-z]*(?:_\d*)?$/', '', mb_substr($scrubbedText, 0, $length));
    }

    /**
     * Rehydrate tokens in tool-call arguments before the tool runs. The model only ever saw tokens
     * for scrubbed values, so it passes e.g. search="[email_1]"; the tool must receive the real
     * address or its lookup finds nothing. Applied on every execution path (read, stream, confirm).
     *
     * @param array<array-key,mixed> $input
     * @return array<array-key,mixed>
     */
    public function rehydrateArguments(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $input[$key] = $this->rehydrateArguments($value);
            } elseif (is_string($value)) {
                $input[$key] = $this->vault->rehydrate($this->canonicalTokens($value));
            }
        }

        return $input;
    }

    /**
     * Token types that never rehydrate into a write, resolvable or not: an admin URL embeds the admin
     * secret key, and nothing an administrator asks for needs it in stored data.
     */
    private const WRITE_REFUSED_TYPES = '/(?:\[|mago:\/\/)url_\d+\]?/';

    /**
     * Text a customer wrote (a review, its title, the reviewer's nickname). It never rehydrates into
     * a write either: it is the text a prompt injection arrives in, and copying it into store data
     * would publish whatever the customer wrote under the store's name.
     */
    private const CUSTOMER_WRITTEN_TYPES = '/(?:\[|mago:\/\/)(?:reviewtext|reviewtitle|nickname)_\d+\]?/';

    /**
     * Every masked value except an admin URL: personal data, customer data and ids. They may be
     * written (#114: a contact person in a CMS block is a legitimate write), but the confirmation
     * card shows the real value with a warning, so the administrator decides with it in plain sight
     * rather than approving an opaque token.
     */
    private const MASKED_VALUE_TYPES = '/(?:mago:\/\/(?!url_)[a-z]+_\d+|\[(?!url_)[a-z]+_\d+\])/';

    /**
     * Any vault token, in the scheme form tokens are minted in and the bracket form older
     * conversations stored.
     */
    private const TOKEN = '/(?:\[[a-z]+_\d+\]|mago:\/\/[a-z]+_\d+)/';

    /**
     * A token the model wrote with markdown escapes: "mago://url\_3", "mago\://url_3", "\[name\_1\]". Markdown
     * shows it as the token again, so the admin saw raw token grammar where a link or name belonged
     * (#160), and a write check that only knows the plain form would let it through.
     */
    private const ESCAPED_SCHEME_TOKEN = '/mago\\\\?:\\\\?\/\\\\?\/([a-z]+)\\\\?_(\d+)/';
    private const ESCAPED_BRACKET_TOKEN = '/\\\\?\[([a-z]+)\\\\?_(\d+)\\\\?\]/';

    /**
     * A token cut off at the end of a streamed delta, escaped or not. Every branch consumes at least
     * one character, or it matches the empty string at the end of any delta and nothing is emitted.
     */
    private const PARTIAL_TOKEN = '/(?:\\\\?\[[a-z]*(?:\\\\?_?\d*)?\\\\?'
        . '|m(?:a(?:g(?:o(?:\\\\?:(?:\\\\?\/(?:\\\\?\/[a-z]*(?:\\\\?_?\d*)?)?)?)?)?)?)?)\\\\?$/';

    /**
     * True when any argument carries a token of a write-refused class (see WRITE_REFUSED_TYPES).
     * Checked on the raw arguments BEFORE rehydration, so a resolvable token still refuses.
     *
     * @param array<array-key,mixed> $input
     */
    public function containsSensitiveToken(array $input): bool
    {
        return $this->matchesAnywhere($input, self::WRITE_REFUSED_TYPES);
    }

    /**
     * True when any argument carries customer-written text (see CUSTOMER_WRITTEN_TYPES). Checked on
     * the raw arguments BEFORE rehydration, so a resolvable token still refuses.
     *
     * @param array<array-key,mixed> $input
     */
    public function containsCustomerWrittenToken(array $input): bool
    {
        return $this->matchesAnywhere($input, self::CUSTOMER_WRITTEN_TYPES);
    }

    /**
     * True when any argument carries a masked value that rehydrates into the write (see
     * MASKED_VALUE_TYPES). Checked on the raw, still tokenised arguments.
     *
     * @param array<array-key,mixed> $input
     */
    public function containsPersonalToken(array $input): bool
    {
        return $this->matchesAnywhere($input, self::MASKED_VALUE_TYPES);
    }

    /**
     * @param array<array-key,mixed> $input
     */
    private function matchesAnywhere(array $input, string $pattern): bool
    {
        foreach ($input as $value) {
            if (is_array($value) && $this->matchesAnywhere($value, $pattern)) {
                return true;
            }
            if (is_string($value) && preg_match($pattern, $this->canonicalTokens($value)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mask detected personal values with their class label, without touching the vault. For sinks
     * that must never hold personal data but have no conversation to tokenise into (debug logs).
     */
    public function maskText(string $text): string
    {
        return $this->heuristic->mask($text);
    }

    /**
     * True when any argument still carries a vault token. A write is refused rather than run with one,
     * so a masked value is never written verbatim as "[order_1]" into real data, nor moved into a
     * write by prompt injection.
     *
     * @param array<array-key,mixed> $input
     */
    public function containsToken(array $input): bool
    {
        foreach ($input as $value) {
            if (is_array($value) && $this->containsToken($value)) {
                return true;
            }
            if (is_string($value) && preg_match(self::TOKEN, $this->canonicalTokens($value)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Swap vault tokens in the model's reply back to real values for the admin. Safe on any string,
     * a no-op when the reply carries no tokens.
     */
    public function rehydrate(string $text): string
    {
        return $this->vault->rehydrate($this->canonicalTokens($text));
    }

    /**
     * Rehydrate for a display sink. A token the vault cannot resolve (deleted vault rows, another
     * conversation's token, a forgery) becomes a neutral label instead of raw token grammar the
     * admin never typed (#97 decision 5); write sinks refuse unresolved tokens instead.
     */
    public function displayText(string $text): string
    {
        return (string)preg_replace(
            self::TOKEN,
            '[earlier record]',
            $this->rehydrate($text)
        );
    }

    /**
     * The values behind the tokens in a text, keyed by token, for a display sink that puts them in
     * as text itself: the admin panel renders the answer's markdown with the tokens still in place
     * and only then swaps each one for its value, so a value can never become markup. A token the
     * vault cannot resolve is left out; the panel shows a neutral label for it (decision 5).
     *
     * @return array<string,string>
     */
    public function tokenValues(string $text): array
    {
        if (preg_match_all(self::TOKEN, $this->canonicalTokens($text), $matches) === 0) {
            return [];
        }

        return array_filter(
            array_combine($matches[0], array_map($this->vault->valueOf(...), $matches[0])),
            static fn (?string $value): bool => $value !== null
        );
    }

    /**
     * Split a streamed text delta into the part that can be shown now and a trailing partial token
     * held back as the carry for the next delta. A token can split across SSE chunks, and the panel
     * only recognises a whole one. Both shapes have to be recognised half-written: the bracket form
     * and the "mago://" form. The text itself is not rehydrated; tokenValues() supplies the values.
     *
     * @return array{0:string,1:string} [text to emit now, carry for the next delta]
     */
    public function splitStreamDelta(string $carry, string $delta): array
    {
        $text = $carry . $delta;
        // Every branch has to consume at least one character, or the pattern matches the empty
        // string at the end of any delta and nothing is ever emitted.
        if (preg_match(self::PARTIAL_TOKEN, $text, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return [$text, ''];
        }
        $offset = (int)$m[0][1];

        return [substr($text, 0, $offset), substr($text, $offset)];
    }

    /**
     * Every token in its plain form, whatever markdown escapes the model put in it.
     */
    private function canonicalTokens(string $text): string
    {
        return (string)preg_replace(
            [self::ESCAPED_SCHEME_TOKEN, self::ESCAPED_BRACKET_TOKEN],
            ['mago://$1_$2', '[$1_$2]'],
            $text
        );
    }
}

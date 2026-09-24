<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Privacy;

/**
 * The single choke point (issue #97): a tool result is filtered here before it becomes the tool
 * message sent to the LLM (ChatService::executeTool()'s return). The classification comes from the
 * tool itself (getFieldClassification on the @api interfaces): public fields pass, tokenise fields
 * become stable vault tokens, and every other scalar (strip-classified, or undeclared) is dropped;
 * the legal preference is not-sending over masking (#97 section 8). Undeclared is never public: a
 * tool that declares nothing leaks nothing. PiiClass::ANY in the map classifies undeclared keys for
 * output whose keys cannot be enumerated. Nested arrays are always walked, so wrappers and records
 * keep their shape; a scalar list inherits the rule of the key it sits under.
 */
class PrivacyFilter
{
    /**
     * An admin url embeds the admin secret key, so it may never cross as itself. It is masked
     * instead of dropped, so the panel can hand the admin a link that opens, and a wildcard-public
     * tool cannot make it public by accident.
     */
    private const NEVER_PUBLIC = ['admin_url' => 'url'];

    /**
     * Kept whatever the classification so a tool's failure or ACL denial can still be explained,
     * otherwise a failing action would reach the model as {}. The value is still run through the
     * heuristic and the vault conceal pass. "message" is deliberately not here: it carries a tool's
     * ordinary answer, not its failure, and a sentence is exactly where an identifier travels
     * unrecognised. A tool that answers in prose declares that prose like any other field.
     */
    private const ALWAYS_ALLOW = ['error'];

    public function __construct(
        private readonly ConversationVault $vault,
        private readonly PiiHeuristic $heuristic
    ) {
    }

    /**
     * @param array<string,array{0:string,1?:string}> $classes
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    public function filter(array $classes, array $result): array
    {
        return $this->apply($result, $classes, null);
    }

    /**
     * @param array<array-key,mixed> $node
     * @param array<string,array{0:string,1?:string}> $classes
     * @param array{0:string,1?:string}|null $inherited Rule for numeric keys, from the enclosing key
     * @return array<array-key,mixed>
     */
    private function apply(array $node, array $classes, ?array $inherited): array
    {
        $out = [];
        foreach ($node as $key => $value) {
            $keyStr = is_string($key) ? $key : null;

            // A list element carries no key of its own, so it answers to the rule of the key the
            // list sits under; a named key always re-matches against the map.
            $rule = $keyStr !== null
                ? ($classes[$keyStr] ?? $classes[PiiClass::ANY] ?? null)
                : $inherited;

            // An explicit STRIP rule wins over the structure: a field declared STRIP is dropped
            // whether it arrives as a scalar or as a nested array (so a customer object under a
            // STRIP key cannot leak its leaves through the recursion below).
            if ($rule !== null && $rule[0] === PiiClass::STRIP) {
                continue;
            }

            if (is_array($value)) {
                $out[$key] = $this->apply($value, $classes, $keyStr !== null ? $rule : $inherited);
                continue;
            }

            if ($keyStr !== null && in_array($keyStr, self::ALWAYS_ALLOW, true)) {
                $out[$key] = $this->keep($value);
                continue;
            }

            $class = $rule[0] ?? PiiClass::STRIP;

            if ($keyStr !== null && isset(self::NEVER_PUBLIC[$keyStr]) && $class === PiiClass::PUBLIC) {
                $class = PiiClass::TOKENISE;
                $rule = [PiiClass::TOKENISE, self::NEVER_PUBLIC[$keyStr]];
            }

            if ($class === PiiClass::PUBLIC) {
                $out[$key] = $this->keep($value);
            } elseif ($class === PiiClass::TOKENISE) {
                // An absent value identifies nobody, so it crosses as the nothing it is rather than
                // as a token standing for a row that does not exist.
                $out[$key] = $value === null || $value === ''
                    ? $value
                    : $this->vault->tokenise((string)$value, $rule[1] ?? 'value');
            }
            // STRIP (declared, or the fail-closed default for an undeclared field): drop it.
        }

        return $out;
    }

    /**
     * A kept value (public or envelope) still goes through the PII heuristic: a rehydrated argument
     * the tool echoes back (a lookup miss repeating the search email in its message, or
     * search_orders echoing the query) would otherwise cross to the LLM raw. The vault returns the
     * same token, so the model's continuity is unaffected; a non-string is left as is.
     */
    private function keep(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        // Defang any token-lookalike arriving in tool output BEFORE minting real tokens, so a forged
        // "mago://email_1" planted in a wildcard-public tool's data (a poisoned product name, CMS
        // text) cannot reach the model and be echoed into an argument that then rehydrates to a real
        // value. Both shapes are defanged: conversations minted before the change still hold the
        // bracket form, and a forgery can wear either.
        $value = (string)preg_replace('/\[([a-z]+_\d+)\]/', '($1)', $value);
        $value = (string)preg_replace('#mago://([a-z]+_\d+)#', '($1)', $value);

        // A value the vault already tokenised (an order number, an id) has no signature the
        // heuristic can match when a tool echoes it back in free text; the vault itself does.
        $value = $this->vault->concealKnownValues($value);

        return $this->heuristic->tokeniseFreeText($value, $this->vault);
    }
}

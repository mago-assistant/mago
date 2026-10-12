# Privacy mode (issue #97)

Privacy mode keeps directly identifying customer data out of what the assistant sends to the LLM
provider. It is the only mode (no toggle). Companion documents in this folder: `decisions.md` (the
decision record, its rationale and the 2.0.0 API surface changes), `legal.md` (the GDPR/AI Act
research it rests on).

**Legal preference: not-sending over masking (#97 §8).** Tokenised data with a server-side map is
still pseudonymised personal data (Recital 26, EDPB 01/2025); absent data cannot leak. So direct
identifiers are **stripped** (never sent). Only a **bare linkable id** is **tokenised**, purely so the
assistant can refer to a row across turns (`[customer_N]`, `[order_N]`, `[review_N]`).

## Classification is required (2.0.0)

Every tool declares how its own output crosses to the LLM:

- `Api\Skill\ActionInterface::getFieldClassification(): array` on every skill action.
- `Api\Tool\ToolInterface::getFieldClassification(string $action = ''): array` on every tool;
  `AbstractSkill` implements it by delegating to the named action, flat tools ignore `$action`.

The map is `field => [PiiClass, tokenType?]`, covering every key the result can contain at any
depth. **Undeclared is never public**: a field the map does not name is stripped, so a tool that
declares nothing leaks nothing, and a brand-new or third-party tool that forgets to classify fails
closed instead of leaking. `PiiClass::ANY` (`'*'`) classifies keys that cannot be enumerated
(dynamic config paths, status codes used as keys); declaring it is an explicit assertion about all
of them.

### Cookbook: the four canonical shapes

```php
// 1. A lookup returning customer PII: strip identifiers, tokenise the bare id and the city, keep the country.
public function getFieldClassification(): array
{
    return [
        'entity_id' => [PiiClass::TOKENISE, 'customer'],
        'name' => [PiiClass::STRIP],
        'email' => [PiiClass::STRIP],
        'telephone' => [PiiClass::STRIP],
        'country' => [PiiClass::PUBLIC],
        'city' => [PiiClass::TOKENISE, 'city'],
    ];
}

// 2. A list of linkable ids: tokenise the ids, counts and dates are public.
return [
    'customer_id' => [PiiClass::TOKENISE, 'customer'],
    'period' => [PiiClass::PUBLIC],
    'total_new' => [PiiClass::PUBLIC],
];

// 3. A PII-free tool: enumerate the public fields by name (nothing is public by omission).
return [
    'sku' => [PiiClass::PUBLIC],
    'name' => [PiiClass::PUBLIC],
    'price' => [PiiClass::PUBLIC],
];

// 4. Output whose keys cannot be enumerated: assert the whole surface deliberately.
return [PiiClass::ANY => [PiiClass::PUBLIC]];
```

Do not declare `error` (envelope, always kept and heuristic-rescrubbed). Do not declare `admin_url`
(always stripped centrally, it embeds the admin secret key; the panel re-attaches deep links
UI-side). A field carrying a full backend URL under another name is `[PiiClass::TOKENISE, 'url']`
(AdminNavigator does this): the provider sees `[url_N]`, display rehydration hands the admin the
real link. An explicit `'message' => [PiiClass::STRIP]` beats the envelope allowance when an ack
message would embed something the vault cannot conceal (for example a bare review id).

## The egress paths and their defenses

| Path | Defense |
|---|---|
| Tool output | `PrivacyFilter` at `ChatService::executeTool()`'s return: declared public passes, tokenise becomes a stable vault token, everything else is stripped. Kept strings are defanged (forged token lookalikes neutralised), vault-concealed (a value the vault already tokenised, like an echoed order number, becomes its token) and heuristic-rescrubbed. |
| The admin's typed message | Scrubbed at the wire boundary (`scrubMessages`) and **persisted tokenised** (decision 1); the conversation title derives from the scrubbed text. History replay and the custom system prompt go through the same scrub. |
| Model-generated tool-call arguments | Rehydrated to real values before the tool runs, on every path (read, stream, confirm; the confirm round-trip re-binds the vault via the conversation id). Three write guards: an admin URL token never rehydrates into a write, resolvable or not (it embeds the admin secret key); customer-written text (`reviewtext`, `reviewtitle`, `nickname`) never rehydrates into a write either; and a token the vault cannot resolve is refused. Every other token (personal data such as name, email, city or phone, and ids such as order, customer or review) rehydrates into a confirmed write; the confirmation card shows the admin the rehydrated values and warns that the write carries values that were masked for privacy (#114). |
| The model's reply | Stored tokenised. The admin panel receives it tokenised too, with the value of each token beside it (on every streamed `text` event as `tokens`, and as `tokens` in the history load): it renders the markdown, sanitizes it and only then puts each value in as a text node, so a value can never become markup, a link or an image. Only an admin URL token resolves to a link target: as a markdown link target, or written bare in the text, where it becomes a link when the URL is on this store. A token the model wrote with markdown escapes (`mago://url\_1`) resolves like the plain one, and an escaped token in a write argument is refused like the plain one (#160). A token the vault cannot resolve shows a neutral label instead (decision 5). |
| The REST chat API (`Model/WebApi/ChatManagement.php`) | Returns the reply and history with the real values already in place (server-side rehydration). Those values include text customers wrote, such as reviews: a REST client must treat the content as untrusted and show it as plain text, or sanitize it as strictly as the admin panel does. |
| The wire itself | `EgressTripwire` (decision 6): in developer mode every outbound payload is scanned once more, independently of the paths above; a hit is logged (classes and position, never values). Under the test flag it throws, so a filter regression fails a test run loudly. Production skips the scan. |

The typed-input hint in the chat panel warns non-blockingly when a message looks like it carries
personal data (decision 3); the certain classes (email, IBAN, BSN, VAT, NL phone) are tokenised
server-side regardless. Debug logging of the raw request body is gated behind the debug flag and
masked by class (irreversibly, no vault) so the log never holds what decision 1 keeps out of the
message store. The panel renders model output HTML-escaped before markdown parsing, so injected
raw HTML in a reply shows as text instead of executing in the admin session. Only the model's own
answer is rendered as markdown: what the admin typed, confirmation cards and the panel's notices
are always shown as plain text.

## Files (`Service/Privacy/`)

| File | Role |
|---|---|
| `PiiClass.php` | The classes `public` / `tokenise` / `strip` and the `ANY` wildcard. |
| `ConversationVault.php` | Reversible per-conversation token map; stable, typed tokens; conceals known values in kept text. |
| `PrivacyFilter.php` | Choke-point filter working off the tool's own declaration; fail-closed. |
| `PiiHeuristic.php` | Detects email / IBAN / BSN / VAT / Dutch phone in free text (checksums), for the paths with no declared field to classify; also powers the tripwire's independent detection. |
| `EgressTripwire.php` | Last-line outbound scan (decision 6). |
| `PrivacyService.php` | Request-scoped facade ChatService uses: filter results, scrub messages and single texts, rehydrate arguments, display rehydration, begin the conversation vault. |

## Verification

- `PrivacyFilterTest` proves the real PII tool shapes strip/tokenise correctly, fail-closed
  semantics, envelope handling, defang and conceal.
- `FieldClassificationCoverageTest` walks every concrete class under `Service/Skills` and validates
  the shape of its declaration, so a malformed map fails the build.
- `ChatServiceTest` carries the end-to-end canary (customer PII never reaches the provider payload)
  and the write-path token tests.
- `EgressTripwireTest` proves the tripwire's log/throw/skip behavior.

```bash
# from the Magento root
vendor/bin/phpunit -c vendor/mago-assistant/magento2-mago/phpunit.xml.dist
```

## Residual risks (state honestly to merchants)

Names in free-form prose and admin-pasted PII have no signature and are only mitigated (best-effort
scrub, non-blocking hint, honest docs), not closed. No directly identifying data leaves from the
shop's structured records; free text the admin types is filtered best-effort. Avoid pasting customer
PII, and the provider DPA is still required for any residual personal data.

## Related (separate tickets)

- page_form field classification (once PR #67 lands in main).
- ConfigReader allowlist (#106), admin_url secret key (#107), retention and right-to-erasure (#108).

# Building a Mago tool

A step-by-step guide for adding a tool the admin chat can call, written for developers and for
coding agents alike. A tool lives in its own Magento module: one class implementing
`MagoAssistant\Mago\Api\Tool\ToolInterface` and one `di.xml` entry that adds it to
`MagoAssistant\Mago\Service\Tool\ToolRegistry`. Nothing inside `vendor/mago-assistant/magento2-mago` is
edited. Reference implementation: https://github.com/mago-assistant/vies

For the architecture behind it, see [skills-architecture.md](skills-architecture.md).

## Using a coding agent

Point your agent at this file. Most agents read `AGENTS.md` in the project root (Claude Code reads
`CLAUDE.md`, which can contain the single line `@AGENTS.md`). Add:

```markdown
When asked to build a Mago tool or skill, follow vendor/mago-assistant/magento2-mago/docs/building-a-tool.md.
```

The guide always matches the installed Mago version, so there is nothing to copy or keep in sync.

## 1. Decide before writing code

| Item | Example | Notes |
| --- | --- | --- |
| Tool name | `vies_vat_check` | snake_case, prefixed with the module, unique in the registry |
| Module | `MagoAssistant_Vies` | `Vendor_Module` |
| Access | `read` | `write` means the admin confirms every call in the panel |
| Irreversible | no | Deletes, sends, refunds: implement `IrreversibleToolInterface` |
| Magento ACL | `Magento_Sales::actions_view` | The resource an admin needs for the same action in the backend |
| Output fields | `valid`, `name`, ... | Each needs a privacy class, see step 4 |

## 2. Scaffold the module

```bash
bin/magento mago:tool:create vies_vat_check MagoAssistant_Vies --acl=Magento_Sales::actions_view --access=read
```

`--acl` and `--access` are required, so a tool never comes out unguarded or read-only by accident.
The module is written to `app/code/<Vendor>/<Module>/`. An existing directory is never overwritten.

To develop the tool as its own composer package (for example to publish it later), pass the module
directory with `--path`, e.g. `--path=package-source/magento2-vies`. The command then prints the
path repository and `composer require` steps to install it from there. `--class` and `--package`
override the derived class and composer names.

When the ACL resource belongs to the new module (`MagoAssistant_Vies::check`), the command also
writes `etc/acl.xml` declaring it under the assistant's resource tree.

Add every module the tool reads from (e.g. `Magento_Sales`) to `<sequence>` in `etc/module.xml`
and to `require` in `composer.json`.

## 3. Write the tool class

The methods, and what goes wrong with each:

- `getName()`: must equal the `di.xml` item name.
- `getDescription()`: the model picks tools by this text. Say what the tool does and what it does
  not. Never promise more than `execute()` returns. Keep it short; it is sent on every request.
- `getParameterSchema()`: JSON Schema with `type: object`. The model fills in every parameter it is
  shown, so only offer parameters that are always safe to receive. Removing a parameter works
  better than a description asking the model not to use it.
- `getMagentoAcl(array $input = [])`: an empty `$input` must return the most restrictive resource
  the tool can reach (fail closed). A tool that touches no Magento data returns
  `Acl::MAGO_PER_USER`, which gates it by the per-user skill permission alone. Never `''`: an
  empty declaration is refused for everyone, and `mago:tool:verify` fails on it.
- `isReadOnly()` / `isReadOnlyAction(array $input)`: `false` triggers a confirmation in the panel.
  A mixed tool answers per action, and an unknown action counts as a write.
- `getFieldClassification(string $action = '')`: a whitelist, see step 4.
- `getInstructions()`: reaches the model only after the first call. Use it for how to present the
  result, never for how to call the tool.
- `execute(array $params)`: return an array. For an expected failure (an external service is down,
  nothing found) return `['error' => '...']` so the model can explain it.

Optional interfaces in `MagoAssistant\Mago\Api\Tool`:

- `IrreversibleToolInterface`: `isIrreversibleAction()` and `getImpacts()` show an impact list and
  an acknowledgement checkbox instead of a plain Allow button.
- `HighImpactToolInterface`: `getCautions()` does the same for a write that can be reverted but
  changes something to weigh first (security, URLs, mail, storefront scripts), so a write proposed
  from text the assistant read does not pass on one unread click.
- `ValidatingToolInterface`: `findRefusal()` rejects a proposed write before the confirmation.
- `ActionScopedToolInterface`: for tools with an `action` parameter whose read and write actions
  must be exposed separately per permission.

Use Magento repositories and `SearchCriteriaBuilder`, never raw SQL, and inject
`Magento\Framework\HTTP\Client\Curl` for external HTTP with an explicit timeout.

## 4. Classify every returned field

`getFieldClassification()` maps each key of the `execute()` result to a rule from
`MagoAssistant\Mago\Service\Privacy\PiiClass`:

- `[PiiClass::PUBLIC]`: sent as is. Only for data that is not personal.
- `[PiiClass::TOKENISE, '<type>']`: the model sees `mago://<type>_1`, the admin sees the real value.
  Types in use: `name`, `email`, `phone`, `address`, `customer`, `order`, `invoice`, `shipment`,
  `creditmemo`, `url`, `entity`, `review`, `reviewtitle`, `reviewtext`, `nickname`.
- `[PiiClass::STRIP]`: never sent.
- Key `PiiClass::ANY` (`'*'`): the rule for every key the map does not name. Only for output whose
  keys cannot be enumerated.

How the privacy filter applies the map:

- Keys match by name at every depth, not by path: `name` covers `name` in every nested row.
- Nested arrays are walked, so a wrapper key (`rows`, `last_24h`) needs no rule of its own. A list
  of scalars inherits the rule of the key it sits under.
- A scalar without a rule is stripped silently. When the assistant answers as if data is missing,
  check this map first.
- `error` always crosses, so a failure can be explained.
- `admin_url` classified as `PUBLIC` is tokenised anyway, because it embeds the admin secret key.
  Left out of the map, it is stripped like any other field.
- An explicit `STRIP` on a key drops the whole subtree under it.

See [privacy-mode/README.md](privacy-mode/README.md) for the canonical output shapes.

## 5. Install and verify

`mago:tool:create` prints these commands with your names filled in:

```bash
bin/magento module:enable MagoAssistant_Vies
bin/magento setup:upgrade
```

With `--path` outside `app/code`, two composer steps come first:

```bash
composer config repositories.mago-assistant-magento2-vies '{"type": "path", "url": "package-source/magento2-vies"}'
composer require mago-assistant/magento2-vies:@dev
```

Then verify, and fix every `[fail]` line:

```bash
bin/magento mago:tool:verify vies_vat_check '{"vat_number": "NL123456789B01"}'
```

It checks the name, description, schema, ACL resources (including whether they are declared in
any `acl.xml`), access mode and classification rules, then runs the call and prints the tool
definition and **what the model sees** after the privacy filter. Returned fields that the
classification does not cover are reported as failures. A call that writes is skipped unless you
pass `--allow-write`, which runs it against the store. `--show-raw` also prints the unfiltered
result; it can contain customer data, so do not paste it into an LLM conversation.

Finally, ask a question the tool should answer in the admin chat panel, and confirm the model
calls it.

Never test a "module disabled" case with `module:disable` plus `setup:upgrade` on a shared
database: declarative schema drops the tables of disabled modules, and re-enabling recreates them
empty while their data patches stay marked as applied. Use a throwaway database or a unit test.

Access: an admin can use the tool when they hold `MagoAssistant_Mago::assistant_read` (or
`assistant_write` for writes), unless a row in `mago_skill_permission` for that admin and tool
says otherwise, and the Magento ACL resource from `getMagentoAcl()` allows it. A tool declaring
`Acl::MAGO_PER_USER` instead needs that row, an explicit grant under Stores > Admin Assistant > Skills & Permissions, and
is not covered by the module-wide resources. Without the row the model still sees the tool (its read actions only, when it
has any) and relays the refusal; the slash legend and command menu leave it out. Return `MAGO_PER_USER` for the whole tool,
not from a single action's `getActionAcl()`.

## 6. README

Fill in the generated `README.md`: what the tool does, two example questions for the chat panel,
and the privacy class of every returned field.

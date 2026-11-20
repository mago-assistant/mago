# Tool manifests: offering a module's REST API to the chat without PHP

A module that has a web API can offer it to the admin chat with one file, `etc/mago_tools.xml`.
Mago reads the manifest, builds a tool from it and runs each action through the module's own
`webapi.xml` routes, in-process, as the admin using the chat. The module does not depend on Mago:
without Mago installed the file is never read, `setup:di:compile` is unaffected, and nothing in the
module references a Mago class.

This is the path for a module that must install on stores with and without Mago (a Marketplace
extension, a Mage-OS distribution module). For a tool that is Mago-first, or that needs PHP logic
no route provides, build an add-on instead: [building-a-tool.md](building-a-tool.md).

| Need | Path |
| --- | --- |
| The module has REST routes with admin ACL resources, and the tool is "call those routes" | **Manifest** (this guide) |
| PHP logic, external services, data no route exposes; Mago is a hard dependency | Add-on module with a `ToolInterface` class ([building-a-tool.md](building-a-tool.md)) |
| Both: routes for the common actions, PHP for one composite step | A manifest tool and an add-on tool side by side, each under its own name |

## 1. Draft it

```bash
bin/magento mago:tool:manifest MageOS_RMA            # prints the draft
bin/magento mago:tool:manifest MageOS_RMA --write    # writes etc/mago_tools.xml into the module, never overwrites
```

The draft has one `<tool>` per top-level REST resource (`/V1/rma/...` becomes `rma`) and one
`<action>` per route, named by verb and path (`list_status`, `get_items`, `create_comments`,
`delete_reason`). It takes the path parameters as parameters, typed by the service contract; reads
are `readonly`, deletes `irreversible`, and the route's ACL resource is on the tool, or on the action
when it differs. Routes for customers and guests (resource `self` or `anonymous`) are left out: a
chat tool is an admin's tool.

Every decision left to you is a `TODO`. `mago:tool:verify` fails while one is left in an attribute
or in `<instructions>` (descriptions, what a search matches on, `impact`, `status`, `acl`). The
others are XML comments that verify cannot see, so read the draft: which returned fields the model may see (only numeric ids are drafted public, the rest is
stripped until you classify it), and what the draft could not work out. Body fields are drafted when
the route takes one entity whose interface can be read, from its return types or its `@return`
docblocks; for anything else (several arguments, a list of objects, an untyped argument) a comment
says what to declare by hand. A `GET` route's extra arguments become query parameters; a `DELETE`
cannot carry any, so those are a comment too. Give the tool a name of your own (`vendor_rma`, not `rma`): a name another tool
already has is left out.

## 2. The file

```xml
<config version="1">
    <tool name="rma" acl="MageOS_RMA::rma_manage" module="MageOS_RMA" title="Returns (RMA)"
          description="Find and handle return requests: look one up, change its status, add a comment.">
        <instructions>Resolve a number to an id with get_by_number before changing anything.</instructions>

        <action name="search" description="Find RMAs by number or status" method="GET" path="rma/search"
                readonly="true" sort="created_at:desc" status="Searching returns...">
            <param name="number" type="string" description="Part of the RMA number" in="criteria" criteria="increment_id" condition="like" wrap="%"/>
            <param name="status_id" type="integer" description="Only RMAs in this status" in="criteria" condition="eq"/>
            <param name="limit" type="integer" in="page_size" default="20"/>
            <field name="entity_id" class="public"/>
            <field name="increment_id" class="public"/>
            <field name="status_id" class="public"/>
            <field name="customer_name" class="tokenise" token="name"/>
            <fields class="strip"/>
            <pick field="entity_id"/>
            <pick field="increment_id"/>
            <pick field="status_id"/>
            <pick field="customer_name"/>
            <link label="Open RMA {increment_id}" route="rma/rma/edit" param="entity_id" from="entity_id"/>
        </action>

        <action name="add_comment" description="Add a comment to an RMA" method="POST" path="rma/{rma_id}/comments" body="comment">
            <param name="rma_id" type="integer" required="true" in="path"/>
            <param name="comment" type="string" required="true"/>
            <param name="is_visible_to_customer" type="boolean" default="false"/>
            <field name="entity_id" class="public"/>
            <fields class="strip"/>
        </action>

        <action name="set_status" description="Move an RMA to another status" status="Changing the status of RMA {entity_id}...">
            <param name="entity_id" type="integer" required="true" in="path"/>
            <param name="status_id" type="integer" required="true"/>
            <step name="save" method="PUT" path="rma/{entity_id}" body="rma">
                <field name="entity_id" value="{entity_id}" as="int"/>
                <field name="status_id" value="{status_id}" as="int"/>
            </step>
            <field name="entity_id" class="public"/>
            <field name="status_id" class="public"/>
            <fields class="strip"/>
        </action>
    </tool>
</config>
```

Inside `<tool>` and `<action>` the elements come in a fixed order: `<instructions>`, then
`<param>`, `<step>`, `<field>`, `<fields>`, `<pick>`, `<link>` (in a tool: `<instructions>`, then
its `<action>` elements). A file in another order does not validate and is left out.

The schema is `etc/mago_tools.xsd` in this module. `version` on `<config>` is the version of this
format the file is written in, currently `1`, so a later Mago can keep reading manifests shipped
before it. The complete manifest for the Mage-OS RMA module is in
[examples/MageOS_RMA/etc/mago_tools.xml](examples/MageOS_RMA/etc/mago_tools.xml).

### How a manifest is read

- Every file is validated against the schema on its own, in every mode. Magento validates its
  other configuration in developer mode only; a manifest is validated in production too.
- A file that is not valid is logged under `var/log/mago-error.log` (context `ManifestTools`) with
  the file name and the reason, and left out. The other modules' tools and the built-in tools keep
  working.
- Files are not merged. A tool belongs to the module that declares it first: a later file that
  declares a tool of the same name is logged and that declaration left out. A manifest tool named
  like a built-in tool is left out too, without a log line: `mago:tool:verify <name>` then shows the
  built-in tool, whose class is on the first line. One module cannot change another module's tool.
- A module declares its own tools only: a tool whose `module` is not the module the file sits in is
  logged and left out.
- The result is cached with the configuration: clean the `config` cache after a change.

### `<tool>`

| Attribute | Meaning |
| --- | --- |
| `name` | Tool name, snake_case, unique among all tools. Prefix it with your vendor or module. |
| `acl` | The Magento ACL resource an admin needs for every action of the tool. The route's own resources apply on top, as they would for a REST call. |
| `module` | The declaring module, which has to be the module the manifest is in. The tool exists only while that module is enabled, and is listed under this name on the Skills & Permissions page. |
| `description` | What the model picks the tool by. Say what it does and does not do. |
| `title` | Name on cards and in the session log; defaults to the tool name as words (`vendor_rma` is shown as "Vendor rma"). |
| `<instructions>` | Reaches the model after the first call: how to present results, what to check first. |

### `<action>`

An action is one or more REST calls. The common case is one call, declared on the action itself
with `method` and `path`. An action with `<step>` elements runs those in order; if it has a
`method` and `path` of its own as well, that call is its first step, named `main`, and no `<step>`
can have that name.

| Attribute | Meaning |
| --- | --- |
| `name`, `description` | Action name (the `action` enum value) and what the model picks it by. Unique within the tool. |
| `method`, `path` | `GET`, `POST`, `PUT` or `DELETE` and the route without `/V1/`. `{param}` is replaced by that parameter, URL-encoded. |
| `body` | Wrapper key for the body of a `POST`/`PUT` (`body="rma"` sends `{"rma": {...}}`), as the service argument is named. It holds for the action's steps too, unless a step names its own. |
| `readonly` | `true` runs without confirmation; anything else is confirmed in the panel. Only an action whose steps are all `GET` can be readonly: a write marked readonly is treated as a write, and `mago:tool:verify` fails on it. |
| `irreversible`, `impact` | A delete or send shows `impact` (parameters as placeholders; a list the model sent is shown comma-separated, a boolean it sent as yes or no; a manifest default is shown as written) with an acknowledgement checkbox. `impact` without `irreversible="true"` is never shown. |
| `acl` | A resource this action needs in addition to the tool's; the admin needs both. |
| `sort` | `field`, `field:asc` or `field:desc` for a search. It holds for the action's steps too, unless a step names its own. |
| `status` | Status line while the call runs; parameters as placeholders. |
| `result` | Which step's response is the action's result: one placeholder naming a whole step, `{steps.save}` (not a field of it). Default, and when `when` skipped that step: the last step that ran. |
| `<instructions>` | Per-action text appended to the tool's instructions under a heading. |

### `<param>`

| Attribute | Meaning |
| --- | --- |
| `name` | Parameter name, unique within the action. `action` and `steps` are the tool's own. |
| `type`, `items` | `string`, `integer`, `number`, `boolean` or `array`; `items` is the type of a list's values (`string`, `integer`, `number`). |
| `description` | What the model reads. When several actions share a name with different descriptions, the model is shown each with its action. |
| `required` | The call is refused without it, and a write is refused before the admin is asked to confirm it. A path parameter is always required. A required filter cannot be an empty list. |
| `default` | Filled in when the parameter is not given; comma-separated for a list. It has to be a value of the parameter's own type and enum. |
| `enum` | Comma-separated allowed values. Enforced: another value is refused before any call. For a list it applies to each value. |
| `in` | Where it goes; see below. |
| `criteria`, `condition`, `wrap` | For a filter: the field to filter on (the parameter's own name when left out), the condition type (`eq`, `like`, `in`, ...) and a wrap around the value (`%` for `like`). A list is joined with commas, for `in`. |

Where a parameter goes (`in`):

| `in` | Effect |
| --- | --- |
| `body` (default) | A field of the body of a `POST`/`PUT`. Never sent by a `GET` or `DELETE`. |
| `path` | A `{name}` segment of the path: one text or number, never a list or a boolean. Any parameter named in the path of a step that always runs is required, whatever its `in`. Path parameters are in the body of a write too, as Magento wants the id in the entity. |
| `criteria` | A `searchCriteria` filter of a `GET`. |
| `query` | A query parameter of a `GET`, as is. A `DELETE`, `POST` or `PUT` carries no query parameters. |
| `page_size` | The page size of a `GET`: default 20, at most 50, always the first page; `type="integer"`. |
| `store` | The store code the call runs in, as `/rest/<code>/V1/` would; `type="string"`. A value that is no store code is refused. Without one the call runs in the default store view, so a module whose texts live in the default scope gives this parameter `default="all"`. |
| `none` | Sent nowhere by itself; for use in a `<step>`'s `<field value="{name}">`. |

A `GET` with a `criteria` or `page_size` parameter, or a `sort`, sends a `searchCriteria` query. So
does a `GET` on a route whose service takes search criteria (a repository's `getList`), also when
the manifest declares none of them: without a page size such a route answers with the whole table.
Any other `GET` is a plain `GET`.

**What the model sends is not trusted.** Before any call, every value is checked against its `type`
(a whole number sent as `5.0` or `"5"` is the integer 5; a list has to be a list of `items`), its
`enum`, and for a path parameter that it is a single value. A call that fails one of them is
refused with a message the model can act on. For a write this happens before the confirmation card,
so the admin is not asked to confirm a call the tool can already tell it would refuse. Two things
can only be judged at the call itself: a value privacy mode shows the model as a token (the real
value is put back after the admin confirms), and a path built from an earlier step's answer.

**A value does not choose the route.** Magento matches a literal route before one with a variable:
with `path="faq/{code}"` and a route `/V1/faq/search` next to `/V1/faq/:code`, the value `search`
would call the search route. The tool refuses a call whose route would differ from the one a neutral
value reaches. Where a path segment is meant to be one of a few literal routes
(`rma/{id}/{transition}` for `/approve` and `/reject`), give that parameter an `enum`: its values
are the manifest's own, and only those are accepted. Each call looks its route up for this; the
lookups are kept for the request.

**Given or not given.** The model is shown one schema for the tool, in the plain form every
provider accepts: one type per parameter, and only `action` required. Which action needs a
parameter is added to its description ("required for search, get"), and checked when the call
comes in. A parameter the model leaves out, or sends as `null` or an empty string, is not given, and
then the `default` applies. Every other value is a value, `0` and `false` included, so a filter on
`is_active = 0` works and `false` replaces a default of `true`.

**One name, one meaning.** A parameter name carries one type and one enum across the tool's
actions; `mago:tool:verify` fails on a name that differs between two actions.

### `<step>`

```xml
<step name="load" method="GET" path="rma/{entity_id}"/>
<step name="save" method="PUT" path="rma/{entity_id}" body="rma">
    <field name="entity_id" value="{entity_id}" as="int"/>
    <field name="order_id" value="{steps.load.order_id}" as="int"/>
    <field name="status_id" value="{status_id}" as="int"/>
</step>
<step name="comment" method="POST" path="rma/{entity_id}/comments" body="comment" when="{note}">
    <field name="comment" value="{note}"/>
    <field name="is_visible_to_customer" value="1" as="int"/>
</step>
```

- Steps run in order; step names are unique within the action.
- `{steps.<name>.<field>}` reads the response of a step that ran before, also in a path.
- A step with `when` runs only when that expression resolves to something non-empty (`0` counts as
  something, `false` and an empty list do not). Use one placeholder: there are no comparisons or
  negations, so `when="!{flag}"` is a text that is never empty and always runs. If no step of an action runs, the action answers
  with an error instead of looking done.
- A step's `<field>` elements make up its body; without them the body is every body and path
  parameter. `as` casts the value: `raw`, `string`, `int`, `list`. A field whose value resolves to
  nothing, or cannot be read as the type `as` names, is left out of the body: `as="int"` takes a
  whole number only (`5.7` and `abc` are left out, never rounded), and `as="list"` wraps a single
  value without splitting it on commas.
- A step that fails stops the action. Steps before it are not undone; when one of them wrote, the
  error says which, so the model does not repeat it.
- The values in `<field value="...">` and the manifest defaults are not shown on the confirmation
  card, which lists what the model sent. Say in the action's `description` what the action sets.

### `<field>`, `<fields>`, `<pick>`, `<link>`

Privacy mode decides what of a result reaches the model, by field name at every depth; see
[privacy-mode/README.md](privacy-mode/README.md).

- `<field name="increment_id" class="public"/>` sends the field to the model. Mago still looks at
  public text for what is personal data by its form (an e-mail address, a phone number, a number
  that passes the BSN check) and shows the model a token for it; `class="tokenise" token="name"`
  shows the model a token and the admin the value; `class="strip"` never sends it.
- `<fields class="strip"/>` is the rule for every field not named; declare it on every action
  (`mago:tool:verify` warns without it, since the names of unclassified nested fields would still
  reach the model). Under it a field without a rule is left out, and so is everything inside it: a nested object or list needs a rule for its own name
  (`<field name="items" class="public"/>`) before the rules for the fields inside it apply.
- A customer can write text into store data, and text the model reads is where a prompt injection
  arrives. Classify fields a customer or another outside party can write (comments, reasons, notes)
  `tokenise`, not `public`. `mago:tool:verify` warns on a public field with such a name.
- `<pick field="entity_id"/>` limits the result (each row of a list, or the record) to the fields
  named, so a wide entity does not flood the context. A picked field still needs a rule. `<pick>`
  and a link's `from` name fields of the record itself, not fields inside another
  (`customer.email` finds nothing).
- `<link label="Open RMA {increment_id}" route="rma/rma/edit" param="entity_id" from="entity_id"/>`
  adds an admin link, rendered as a button in the panel: one for a record, one per row of a list.
  The label is read from the whole record, before `pick`, but only from fields the action classifies
  `public`: a label reaches the model as text, so a placeholder for a tokenised or stripped field is
  left empty, and `mago:tool:verify` fails on it. The link's url is always a token to the model.

**Lists and records.** A result with `items` next to `total_count` (what `getList` answers), or a
bare list, is a list: rows are filtered, picked and linked one by one. A list reaches the model as
`items` with its `total_count` without the manifest naming either, and without what else a search
result carries (`search_criteria`); an empty answer to a `GET` is an empty list. In the rows only
named fields count: a list of bare values comes back as its count with no rows. Anything else is a
record, also when it has an `items` field of its own, such as a return with its lines; those lines
are kept, each filtered by the rules of its own fields.

**A bare answer.** A route that answers with `true` or a number (a delete, a save that returns an
id) reaches the model as `{"result": true}`, also when the action has `<pick>` elements. A text
answered that way is left out unless the manifest gives `result` a rule, and so is a field called
`result` inside a record.

**Reserved field names.** `error` is the tool's own error message and `client_directive` is how a
built-in tool steers the admin's browser: a route field with either name is always left out of a
result, and a response that is nothing but an `error` text is read as a failed
call. Do not offer a route that answers that way with text someone outside the store wrote. `items`, `total_count`, `result`, `label`, `url` and `_links` are what the tool
adds to a result; a route field with one of those names is left out unless the manifest gives it a
rule, and in a list the tool wraps, values without a field name (a list of bare strings) are left
out unless `items` has a rule.

## 3. Verify

```bash
bin/magento cache:clean config
bin/magento mago:tool:verify rma                                   # every action, nothing is called
bin/magento mago:tool:verify rma '{"action": "search", "number": "000000012"}'
```

Next to the checks every tool gets, a manifest tool is checked for:

- the declaring module being enabled, and every step's route existing in the store's `webapi.xml`
  (matched as a call would be, with a stand-in in each placeholder);
- the route being one an admin can call: its ACL resources declared in an `acl.xml`, not the
  customer's own (`self`). A manifest resource that differs from the route's is a warning: all of
  them apply, so an admin needs each;
- every placeholder naming a parameter of the action or a field of an earlier step, in paths,
  `when`, `<field value>`, `status`, `impact` and `result`;
- one type and one enum per parameter name across the actions, no parameter called `action` or
  `steps`, no list as a path parameter, and every `default` and `enum` value being one the tool
  would accept;
- an action's own `acl` being declared in an `acl.xml`, and every `tokenise` rule having a valid
  `token` type;
- parameters that would never be sent (a body parameter no step carries, because every writing step
  lists its own `<field>` elements and none names it; a filter without a `GET` step): a warning, and
  a failure when the parameter is `required`, an `impact`
  without `irreversible`, a `sort` that is no `field:direction`;
- no action marked `readonly` that writes;
- an `in="store"` parameter being `type="string"`, an `in="page_size"` parameter `type="integer"`,
  and no boolean as a path parameter;
- things that read as if they work and do nothing: a `when` that is more than one placeholder, a
  `<pick>` or link `from` that names a field inside another;
- what reaches the model: a link label naming a field that is not public (fails), an action without
  field rules or without a `<fields>` catch-all, a public catch-all, public free text, a `<pick>` without a rule, a rule for `error`
  or for `url` next to links (warnings);
- no `TODO` left over from the draft.

When the tool is not found, the command says where to look: the manifest was left out (see
`var/log/mago-error.log`), the `config` cache is stale, or the name is taken.

Running the call from the command line answers with a permission error: in-process calls run as
the admin using the chat, and the command line has none. Test the call in the chat panel.

## 4. What stays in PHP

A manifest calls routes; it has no conditionals beyond `when`, no arithmetic and no access to
anything the routes do not return. Drafting content from product data, comparing two entities,
talking to an external service: those are an add-on with a `ToolInterface` class
([building-a-tool.md](building-a-tool.md)), which can live next to the manifest in the same
module when that module may depend on Mago, or in its own module when it may not.

Routes are matched against the store's merged `webapi.xml`, so a route another module adds or
removes changes what the manifest can call. The `webapi_rest`-only plugins the in-process client
replaces or leaves out apply to manifest calls as to any other; see
[skills-architecture.md](skills-architecture.md#internal-api-client).

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
| The module has REST routes with ACL resources, and the tool is "call those routes" | **Manifest** (this guide) |
| PHP logic, external services, data no route exposes; Mago is a hard dependency | Add-on module with a `ToolInterface` class ([building-a-tool.md](building-a-tool.md)) |
| Both: routes for the common actions, PHP for one composite step | Manifest for the routes, add-on for the rest; the registry merges them |

## 1. Draft it

```bash
bin/magento mago:tool:manifest MageOS_RMA            # prints the draft
bin/magento mago:tool:manifest MageOS_RMA --write    # writes etc/mago_tools.xml into the module, never overwrites
```

The draft has one `<tool>` per top-level REST resource (`/V1/rma/...` becomes `rma`), one
`<action>` per route named by verb and path (`list_status`, `get_items`, `create_comments`,
`delete_reason`), the path parameters as parameters, the body fields read from the service
contract's data interface, reads marked `readonly`, deletes marked `irreversible`, and the route's
ACL resource on the tool (or on the action, when it differs). Every decision left to you is a
`TODO`: descriptions, what a search matches on, which returned fields the model may see.

## 2. The file

```xml
<config>
    <tool name="rma" acl="MageOS_RMA::rma_manage" module="MageOS_RMA" title="Returns (RMA)"
          description="Find and handle return requests: look one up, change its status, add a comment.">
        <instructions>Resolve a number to an id with get_by_number before changing anything.</instructions>

        <action name="search" description="Find RMAs by number or status" method="GET" path="rma/search"
                readonly="true" sort="created_at:desc" status="Searching returns...">
            <param name="number" type="string" description="Part of the RMA number" in="criteria" criteria="increment_id" condition="like" wrap="%"/>
            <param name="status_id" type="integer" description="Only RMAs in this status" in="criteria" criteria="status_id" condition="eq"/>
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

The schema is `etc/mago_tools.xsd` in this module. The complete manifest for the Mage-OS RMA module is
in [examples/MageOS_RMA/etc/mago_tools.xml](examples/MageOS_RMA/etc/mago_tools.xml); Magmodules'
FAQ module ships one in its own `etc/mago_tools.xml`.

### `<tool>`

| Attribute | Meaning |
| --- | --- |
| `name` | Tool name, snake_case, unique in the registry. A name a built-in tool already uses is left out, so a manifest cannot shadow one. |
| `acl` | The Magento ACL resource an admin needs for every action of the tool. The route's own resources apply on top, as they would for a REST call. |
| `module` | The declaring module. The tool exists only while that module is enabled. |
| `description` | What the model picks the tool by. Say what it does and does not do. |
| `title` | Name on cards and in the session log; defaults to the tool name. |
| `<instructions>` | Reaches the model after the first call: how to present results, what to check first. |

### `<action>`

An action is one or more REST calls. The common case is one call, declared on the action itself
with `method` and `path`; a composite action lists `<step>` elements instead.

| Attribute | Meaning |
| --- | --- |
| `name`, `description` | Action name (the `action` enum value) and what the model picks it by. |
| `method`, `path` | `GET`, `POST`, `PUT` or `DELETE` and the route without `/V1/`. `{param}` is replaced by that parameter, URL-encoded. |
| `body` | Wrapper key for the body of a `POST`/`PUT` (`body="rma"` sends `{"rma": {...}}`), as the service argument is named. |
| `readonly` | `true` runs without confirmation; anything else is confirmed in the panel. Only an action whose steps are all `GET` can be readonly: a write marked readonly is treated as a write, and `mago:tool:verify` reports it. |
| `irreversible`, `impact` | A delete or send shows `impact` (placeholders allowed) with an acknowledgement checkbox. |
| `acl` | A resource this action needs in addition to the tool's; the admin needs both. |
| `sort` | `field:asc` or `field:desc` for a search. |
| `status` | Status line while the call runs; placeholders allowed. |
| `result` | Which step's response is the action's result, e.g. `{steps.save}`; default: the last step that ran. |
| `<instructions>` | Per-action text appended to the tool's instructions under a heading. |

### `<param>`

| Attribute | Meaning |
| --- | --- |
| `name`, `type` | Parameter name and JSON type: `string`, `integer`, `number`, `boolean`, `array` (`items` names the element type). |
| `description`, `required`, `default`, `enum` | As in JSON Schema; `enum` is comma-separated. A default is filled in when the model leaves the parameter out. |
| `in` | Where it goes: `body` (default; path parameters are in the body too, as Magento wants the id in the entity), `path` (always required, and checked against `type` before the call, so a stray word cannot become a route segment), `query` (as is), `criteria` (a `searchCriteria` filter), `page_size`, `store` (the store code the call runs in, as `/rest/<code>/V1/` would; without one the call runs in the default store view, so a module whose texts live in the default scope gives this parameter `default="all"`), `none`. |
| `criteria`, `condition`, `wrap` | For `in="criteria"`: the field to filter, the condition type (`eq`, `like`, `in`, ...) and a wrap around the value (`%` for `like`). A list value is joined with commas, for `in`. |

A `GET` with any `criteria` or `page_size` parameter sends a `searchCriteria` query; without one it
is a plain `GET`; the page size is capped at 50. An optional parameter is nullable in the schema
sent to the model, and null or an empty string counts as not given. For a filter (`criteria`,
`page_size`, `store`, `query`) a zero value (0, false, an empty list) counts as not given too: a
provider in strict mode makes the model send every property, and those must not become filters. A body field keeps its 0 or false, also against a manifest default; a required boolean or list may
be false or empty. A zero the model sent for a `criteria` or `query` filter means "do not filter",
so the default does not step in either; a zero page size falls back to the default. Every given
value is checked against its `type` before the call. The schema sent to the model lists each parameter once, and the schema of the
called action is also the allowlist: `execute()` never receives a key the action does not declare.

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

Steps run in order. `{steps.<name>.<key>}` reads an earlier step's response. A step with `when` runs
only when that expression resolves to something non-empty. A step's `<field>` elements make up its
body (cast with `as`: `raw`, `string`, `int`, `list`); without them the body is every body and path
parameter. A step that answers with `error` stops the action, and that error is the result.

### `<field>`, `<fields>`, `<pick>`, `<link>`

Privacy mode decides what of a result reaches the model, by field name at every depth; an
undeclared scalar is stripped (see [privacy-mode/README.md](privacy-mode/README.md)).

- `<field name="increment_id" class="public"/>` sends the field as is; `class="tokenise" token="name"`
  shows the model a token and the admin the value; `class="strip"` never sends it.
- `<fields class="strip"/>` is the rule for every field not named. Declare it: a manifest without
  it strips undeclared fields anyway, but says so less clearly. The `items` and `total_count`
  wrappers of a list route stay public under it, so the named rules reach the rows inside.
- `<pick field="entity_id"/>` limits the result (each item of a list, or the record) to the fields
  named, so a wide entity does not flood the context. `items` and `total_count` always stay. A route
  that answers with a bare list is treated as `items` with a `total_count`.
- `<link label="Open RMA {increment_id}" route="rma/rma/edit" param="entity_id" from="entity_id"/>` adds
  an admin link per record that carries `from`, rendered as a button in the panel.

## 3. Verify

```bash
bin/magento cache:clean config
bin/magento mago:tool:verify rma                                   # every action's route
bin/magento mago:tool:verify rma '{"action": "search", "number": "000000012"}'
```

Next to the checks every tool gets, a manifest tool is checked for: the declaring module being
enabled, every step's route existing in the store's merged `webapi.xml` (matched as a call would
be, with a placeholder in each path parameter), the route's ACL resources being declared in an
`acl.xml`, and the manifest's resource agreeing with the route's (a difference is a warning: both
apply, so an admin needs both). The manifest is read with the configuration cache, so clean `config` after a change. A manifest
that does not validate is logged under `var/log/mago-error.log` (context `ManifestTools`) and its
tools are left out; the built-in tools keep working, and `mago:tool:verify <tool>` then reports the
tool as missing from the registry. Tool names merge across modules like other Magento configuration,
so prefix them with your module.

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

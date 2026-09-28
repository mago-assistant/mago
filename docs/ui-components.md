# Mago UI kit

The chat panel answers with a small set of reusable widgets and skill cards. They
are built by `view/adminhtml/web/js/mago-ui.js` and styled by
`view/adminhtml/web/css/source/module/_components.less`. The design source is the
"Mago Widget Kit" (W01–W21) and "Mago Skill Actions" (S01–S14) pages of the Mago
admin chatbot design project.

## Using the kit

[ui-components-examples.md](ui-components-examples.md) shows every builder with sample data and the call
that produced it. The live version, [ui-components-examples.html](ui-components-examples.html), loads the
module's own JS and LESS; serve the module root over HTTP (`python3 -m http.server 8080`) and open
`/docs/ui-components-examples.html`. `docs/render-examples.sh` refreshes the captures.

The module is an AMD module (`MagoAssistant_Mago/js/mago-ui`) and also sets
`window.MagoUI`. Every builder takes one options object and returns a detached
`HTMLElement`; append it wherever it belongs.

```js
require(['MagoAssistant_Mago/js/mago-ui'], function (MagoUI) {
    var card = MagoUI.stat({label: 'Revenue, 7 days', value: '€38.410', delta: {value: '12,4%', direction: 'up', suffix: 'vs last week'}});
    container.appendChild(card);
});
```

Text options are inserted as plain text. Pass `{html: '...'}` for trusted markup
(for example the output of the markdown renderer) or a DOM node.

The widgets use the CSS custom properties the panel defines (`--mago-ink`,
`--mago-hairline`, `--mago-accent`, ...), so they follow the configured accent
colour. Outside the panel, put the widgets inside an element with class
`mago-kit` so the semantic tokens (success, warning, danger, chart ramp) apply.

Labels default to English and can be overridden per call through `labels`
(`MagoUI.skillAsk({..., labels: {allow: 'Toestaan'}})`) or globally through
`MagoUI.labels`.

## Widgets from an answer

An assistant answer can carry widgets in a fenced block with language `mago`
containing one spec or an array of specs. `type` names the builder, the rest are
its options:

````markdown
Revenue is up this week.

```mago
[
  {"type": "stats", "items": [{"label": "Revenue", "value": "€38.410", "delta": {"value": "12,4%", "direction": "up"}}, {"label": "Orders", "value": "412"}]},
  {"type": "rankedBars", "label": "By category", "items": [{"label": "Accessories", "value": 61}, {"label": "Lighting", "value": 38}]}
]
```
````

While the block is still streaming, a skeleton holds its place; an invalid spec
falls back to a code block. `MagoUI.render(spec)` and `MagoUI.renderJson(json)`
expose the same mapping for other callers. Event handlers cannot travel through
JSON, so specs should use `href` for clickable items.

The assistant learns this format from the `[Answer widgets]` section that
`Service/Ai/AnswerWidgets` adds to the system prompt: the fence, the rules (tool
data only, display strings for numbers, `href` for admin links, no HTML, at most
three widgets per answer) and one example shape per type. Interactive cards are
not offered to the model; the panel builds those from tool events. The section is
controlled by *Stores > Configuration > Mago Assistant > Chat Settings > Answer
Widgets* (`mago/chat/answer_widgets`, default Yes); switching it off keeps the
renderer but stops the model from being told about it.

Inside an answer, clicks work through two attributes the panel handles for every
widget: `data-mago-send="text"` (`MagoUI.sendAttr`) sends the text as the admin's
next message and `data-mago-focus` (`MagoUI.focusAttr`) focuses the input. Chips
and suggestion cards without their own `onClick` or `href` carry
`data-mago-send` with their label. Whatever a builder returns is scrubbed before
it reaches the page (no scripts, event attributes or unsafe links), and a
builder that throws is skipped.

Another module can add its own widget type with `MagoUI.register(type, builder)`
and the `widgets` argument of `AnswerWidgets` in its `di.xml`; see
[widgets.md](widgets.md) for the full walkthrough and
[examples/Vendor_MagoStockAlert](examples/Vendor_MagoStockAlert) for a working
module.

## Widgets (W01–W21)

| # | Builder | Options |
|---|---------|---------|
| W01 | `stat` | `label`, `value`, `delta: {value, direction: up\|down\|flat, suffix}`, `note` |
| W02 | `stats` | `items: [statOptions, statOptions]` (max two) |
| W03 | `sparkline` | `label`, `value`, `points: [n, ...]`, `delta` |
| W04 | `meter` | `label`, `value`, `max`, `valueText`, `note` |
| W05 | `ring` | `label`, `percent`, `valueText`, `legend: [{label, value, color}]` |
| W06 | `composition` | `label`, `segments: [{label, value, valueText, color}]` |
| W07 | `rankedBars` | `label`, `items: [{label, value, valueText, color, onClick}]` |
| W08 | `columns` | `label`, `points: [{label, value, valueText, highlight}]` |
| W09 | `lines` | `label`, `series: [{label, points, style: solid\|dashed}]`, `xLabels` |
| W10 | `stackedColumns` | `label`, `series: [{label, color}]`, `points: [{label, values}]` |
| W11 | `heatmap` | `label`, `rows: [label]`, `values: [[n, ...]]`, `scale: [low, high]` |
| W12 | `funnel` | `label`, `steps: [{label, value, valueText}]` |
| W13 | `entityList` | `items: [{title, meta, thumb, href, onClick, action: {label, href, onClick}}]`, `more: {count, label, href, onClick}` |
| W14 | `table` | `columns: [{key, label, align, width, num}]`, `rows: [{key: value \| {text, badge, tone, strong, num}}]`, `onRowClick` |
| W15 | `record` | `title`, `badge: {text, tone}`, `rows: [{label, value, strong, num}]` |
| W16 | `confirmWrite` | `title`, `text`, `diff: {from, to, delta}`, `onConfirm`, `onCancel` |
| W17 | `toolTrace` | `steps: [{label, state: done\|active\|pending\|failed, tool}]` |
| W18 | `callout` | `tone: warn\|danger\|ok\|info`, `text`, `action: {label, href, onClick}` |
| W19 | `suggestions` | `cards: [{label, icon, href, onClick}]`, `chips: [{label, onClick}]` |
| W20 | `answerFooter` | `primary: {label, href, onClick, external}`, `onCopy`, `onFeedback(vote)` |
| W21 | `empty`, `skeleton` | `title`, `text`, `icon` / `widths` |

Chart colours follow the ramp: the first (highest) item is the accent, the next
ones peach, and from four items on the last one is grey ("the remainder").
Numbers are rendered with tabular figures; pass preformatted strings when the
locale formatting matters, or use `MagoUI.formatNumber(n)`.

## Skill actions (S01–S14)

A write action has one card with a fixed life cycle: ask, run, done. The card
changes in place, it does not move.

| # | Builder | Notes |
|---|---------|-------|
| S01 | `skillAsk` | `title`, `tool`, `text`, `params: [{key, value, mono, muted}]`, `onAllow`, `onAlways` (optional), `onLater`. `MagoUI.paramsFromInput(toolInput)` turns a tool input object into rows. `classes: {actions, allow, later}` adds hook classes for tests. |
| S02 | `skillRunning` | `title`, `elapsed`, `progress`, `steps`, `onStop`. The element exposes `magoUpdate({progress, elapsed, steps})` and `magoAddStep(step)`; a step exposes `magoSetState(state)`. |
| S03 / S05 | `skillLine` | Collapsed result line: `title`, `action`, `duration`, `state: done\|failed\|skipped`, `request`, `response`, `expanded`. Expands on click when a payload is given. |
| S04 | `skillFailed` | `title`, `code`, `text`, `details`, `onRetry`, `onDetails` |
| S06 | `readLine` | `text`, `tool`, `action`, `state: done\|active` — one quiet line per read-only call |
| S07 | `skillIrreversible` | `title`, `tool`, `text`, `impacts: [...]`, `ackLabel`, `confirmLabel`, `onConfirm`, `onCancel`. The confirm button stays disabled until the acknowledgement is ticked. |
| S08 | `skillBulk` | `title`, `items: [{id, label, meta, checked, disabled}]`, `confirmLabel(n)`, `onConfirm(ids, items)`, `onLater` |
| S09 | `skillPlan` | `title`, `current`, `total`, `steps: [{title, meta, state: done\|active\|ask\|pending}]`, `onAllowAll`, `onPause` |
| S10 | `paramPrompt` | `text`, `prefix`, `value`, `placeholder`, `type`, `onSubmit(value)`, `chips: [{label, value}]` |
| S11 | `undoCallout` | `text`, `onUndo`, `undoLabel`, `expiresText` |
| S12 | `sessionLog` | `title`, `entries: [{text, time, tone}]`, `onExport` |
| S14 | `skillMenu` | `skills: [{name, title, description, risk: read\|write\|irreversible, group}]`, `title`, `activeIndex`, `onSelect(skill, index)`, `itemClass`. A `group` opens a heading row above the entry, so one menu can hold several kinds of entry; `activeIndex` and the `onSelect` index stay flat over all of them. Exposes `magoSetActive(index)`. With `onSelect` the rows are listbox options that take no focus: drive them from a text field through `aria-controls` (`magoListId`) and `aria-activedescendant` (`magoItems[i].id`). Without it the menu is a static list. |

## Where the panel uses them

`chat-panel.js` wires the kit into the streaming flow:

- a `confirm` event with one reversible write renders the S01 card with the
  tool's parameters. Allow turns it into the S02 progress card, which collects
  the `tool_status` events of the confirmed run and collapses into an S03 line
  with the duration when the run is done; a `tool_status` of `failed` (the tool
  answered with an error) turns it into the S04 card with the tool's message
  instead. "Not now" leaves a muted line and makes no write call;
- a write whose action implements `IrreversibleActionInterface` arrives with
  `irreversible: true` and an `impacts` list and renders as the S07 card: the
  Allow button stays disabled until the acknowledgement is ticked;
- several writes in one turn render as the S08 tick list; the ticked tool call
  ids go to the confirm endpoint as `tool_call_ids`, unticked calls are answered
  with a "skipped" tool result and never run;
- `tool_status` events for read-only tools render as S06 lines above the answer,
  a failed read shows the error in the line's tooltip;
- `error` events render as a danger callout (W18);
- the slash menu is the S14 skill menu, coloured green for read-only skills and
  orange for skills that write;
- ```` ```mago ```` fenced blocks in an answer render as widgets. Chips and
  suggestion cards send their label as the next question; the S10 value prompt
  sends the typed or picked value;
- the list icon in the header opens the S12 session log: every write the
  assistant ran, skipped or failed in this browser session, kept in
  `sessionStorage`.

Not wired yet: the S09 plan card (needs a planner that announces its steps up
front) and the S11 undo callout (needs reversible tool results on the backend).
Both builders exist for when that lands. "Always allow" (S01) has no backend
either and is not offered; the builder supports it through `onAlways`.

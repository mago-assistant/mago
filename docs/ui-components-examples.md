# Mago UI kit — examples

Every widget and skill card in `view/adminhtml/web/js/mago-ui.js`, rendered at
panel width with sample data. The option reference is in
[ui-components.md](ui-components.md).

The images below are captures of [ui-components-examples.html](ui-components-examples.html),
which renders the live kit and shows the call behind every example. To open it,
serve the module root over HTTP and visit `/docs/ui-components-examples.html`:

```bash
python3 -m http.server 8080
```

To refresh the captures after changing the kit, run `docs/render-examples.sh`
(needs Google Chrome).

## Metrics (W01–W06)

`stat`, `stats`, `sparkline`, `meter`, `ring`, `composition`

![Metrics](examples/metrics.png)

```js
MagoUI.stat({
    label: 'Revenue, 7 days',
    value: '€38.410',
    delta: {value: '12,4%', direction: 'up', suffix: 'vs last week'}
});

MagoUI.meter({label: 'Index queue', value: 1842, max: 2400, note: '77% processed · ± 4 min left'});
```

## Charts (W07–W12)

`rankedBars`, `columns`, `lines`, `stackedColumns`, `heatmap`, `funnel`

![Charts](examples/charts.png)

```js
MagoUI.rankedBars({label: 'Without image, per category', items: [
    {label: 'Accessories', value: 61},
    {label: 'Lighting', value: 38},
    {label: 'Textiles', value: 19},
    {label: 'Other', value: 10}
]});

MagoUI.lines({
    label: 'Revenue, 30 days',
    series: [
        {label: 'now', points: [30, 48, 42, 70, 64, 88, 94]},
        {label: 'previous', points: [22, 34, 28, 56, 50, 78, 86]}
    ],
    xLabels: ['1 Aug', '15 Aug', '30 Aug']
});
```

## Data (W13–W15)

`entityList`, `table`, `record`

![Data](examples/data.png)

```js
MagoUI.table({
    columns: [
        {key: 'order', label: 'Order', num: true},
        {key: 'status', label: 'Status', width: '92px'},
        {key: 'total', label: 'Total', align: 'right', width: '84px'}
    ],
    rows: [
        {order: '#100241', status: {badge: 'Hold', tone: 'warn'}, total: {text: '€248,00', strong: true, num: true}},
        {order: '#100230', status: {badge: 'Paid', tone: 'ok'}, total: {text: '€1.104,00', strong: true, num: true}}
    ],
    onRowClick: function (row) { /* open the order */ }
});
```

## Action & state (W16–W21)

`confirmWrite`, `toolTrace`, `callout`, `suggestions`, `choices`, `answerFooter`, `empty`, `skeleton`

![Action and state](examples/actions.png)

```js
MagoUI.callout({tone: 'warn', text: 'Cache has not been refreshed for 6 days. Numbers may lag.'});

MagoUI.suggestions({
    cards: [{label: 'Revenue versus last week', icon: 'barChart', onClick: ask}],
    chips: [{label: 'Split per country', onClick: ask}, {label: 'Export CSV', onClick: ask}]
});

MagoUI.choices({
    options: [{label: 'Low stock', onClick: ask}, {label: 'Disabled products with stock', onClick: ask}],
    other: 'Something else…',
    onOther: focusInput
});
```

## Skill actions (S01–S14)

`skillAsk`, `skillRunning`, `skillLine`, `skillFailed`, `readLine`, `skillIrreversible`,
`skillBulk`, `skillPlan`, `paramPrompt`, `undoCallout`, `sessionLog`, `skillMenu`

![Skill actions](examples/skills.png)

```js
// S01: ask first. The chat panel builds the params from the tool input.
MagoUI.skillAsk({
    title: 'Cache management',
    tool: 'cache_manager',
    text: 'Flush all cache types. The shop is 1–2 minutes slower while it rebuilds.',
    params: MagoUI.paramsFromInput({action: 'flush', cache_type: ''}),
    onAllow: run,
    onLater: skip
});

// S02: the same card while it runs; feed it tool_status events.
var card = MagoUI.skillRunning({title: 'Flushing cache', progress: 30});
var step = card.magoAddStep({label: 'config, layout, block_html', state: 'active'});
step.magoSetState('done');

// S03: collapsed result line, expandable when a payload is given.
MagoUI.skillLine({title: 'Cache management', action: 'flush', duration: '4,1s', request: {action: 'flush'}});
```

## Widgets from an answer

An assistant answer carries widgets in a fenced block with language `mago`;
`MagoUI.renderJson()` turns the JSON into HTML and the panel calls it from its
markdown renderer. Specs are untrusted: no raw HTML, only web or admin links.

![Widgets from an answer](examples/spec.png)

````markdown
Revenue is up this week.

```mago
[
  {"type": "stats", "items": [
    {"label": "Revenue, 7 days", "value": "€38.410", "delta": {"value": "12,4%", "direction": "up"}},
    {"label": "Orders", "value": "412"}
  ]},
  {"type": "rankedBars", "label": "By category", "items": [
    {"label": "Accessories", "value": 61}, {"label": "Lighting", "value": 38}
  ]},
  {"type": "callout", "tone": "warn", "text": "Rendered from the JSON in a ```mago block."}
]
```
````

# Building an answer widget

Mago answers with widgets: a stat, a ranking, a list of records. The model writes a
` ```mago ` fenced block with a JSON spec, and the chat panel turns that spec into a card.
This guide shows how to add a widget of your own from a separate Magento module, without
changing Mago itself.

A complete, working example lives in
[`docs/examples/Vendor_MagoStockAlert`](examples/Vendor_MagoStockAlert): a `stockAlert` widget
that shows one product that is running out of stock, with a follow-up question the admin can
click.

For the built-in widgets and their options, see [ui-components.md](ui-components.md).

## How a widget gets on screen

```
model answer ──► ```mago {"type":"stockAlert", ...} ```
                        │
                        ▼
      chat panel ──► MagoUI.renderJson() ──► your builder(spec) ──► scrubbed HTML in the answer
```

A widget has three parts, and a module supplies all three:

| Part | Where | What it does |
|---|---|---|
| **Guidance** for the model | `etc/di.xml` → `AnswerWidgets` `widgets` argument | Tells the model the widget exists, its JSON shape and when to use it |
| **Builder** in the browser | `view/adminhtml/web/js/…` via a requirejs mixin on `MagoAssistant_Mago/js/mago-ui` | Turns the spec into DOM |
| **Styles** | `view/adminhtml/web/css/source/_module.less` | Makes it look right in the panel |

Clicks need no script of your own: the panel handles two attributes (see
[Interaction](#interaction)).

## 1. Module skeleton

```
app/code/Vendor/MagoStockAlert/
├── registration.php
├── etc/
│   ├── module.xml
│   └── di.xml
└── view/adminhtml/
    ├── requirejs-config.js
    └── web/
        ├── js/mago-ui-mixin.js
        └── css/source/_module.less
```

`module.xml` must load after Mago, so both the DI argument and the mixin apply on top of it:

```xml
<module name="Vendor_MagoStockAlert">
    <sequence>
        <module name="MagoAssistant_Mago"/>
    </sequence>
</module>
```

## 2. Teach the model the widget

The model only uses widgets it has been told about. Add yours to the `widgets` argument of
`MagoAssistant\Mago\Service\Ai\AnswerWidgets` in `etc/di.xml`:

```xml
<type name="MagoAssistant\Mago\Service\Ai\AnswerWidgets">
    <arguments>
        <argument name="widgets" xsi:type="array">
            <item name="stockAlert" xsi:type="string"><![CDATA[{"type":"stockAlert","title":"Chaz Kangeroo Hoodie","sku":"MH01","qty":2,"href":"/admin/catalog/product/edit/id/67/","ask":"Show the sales of MH01 this month"} — one product that is (almost) out of stock; qty is the salable quantity and href the admin_url from a tool result (leave href out when no tool returned one), "ask" a follow-up question in the user's language.]]></item>
        </argument>
    </arguments>
</type>
```

The entry has the same form as the built-in ones, and it is appended to the list of widgets in
the system prompt:

- **The item name is the widget type.** Use camelCase letters and digits (`stockAlert`).
- **The value is one example spec, then `" — "`, then when to use it.** The example must be valid
  JSON and its `"type"` must equal the item name.
- **You cannot replace a built-in type.** Choose a name of your own; a vendor prefix avoids
  clashes with widgets Mago adds later (`acmeStockAlert`).

An entry that breaks these rules is left out, and the reason is written to
`var/log/mago-error.log` (look for `AnswerWidgets`). The chat keeps working without it.

Writing the guidance well matters more than it seems:

- **Say when, not just what.** "one product that is (almost) out of stock" tells the model when to
  choose this widget over a plain `entityList`.
- **Name where values come from.** The prompt already tells the model to use tool data only;
  repeating it per field helps. Links in particular: in testing, the model filled `href` with a
  made-up product id until the guidance said to take it from a tool's `admin_url`.
- **Watch out for sample text.** The model tends to copy example labels verbatim, in English. For
  free text the user should see, say "in the user's language". If the model still copies an
  example, use placeholders instead (`"label":"<reading 1>"`), as the built-in `choices` widget
  does.
- **Keep the example small.** Every entry is sent with every request.

## 3. Build it in the browser

Register a builder from a requirejs mixin on Mago's UI module. The mixin runs as soon as the
module loads, so the builder is there before the first answer is rendered, including
conversations reopened from history. (The panel loads Magento's `mixins` module before
`mago-ui` for exactly this reason: Magento only applies mixins to modules required after it.)

`view/adminhtml/requirejs-config.js`:

```js
var config = {
    config: {
        mixins: {
            'MagoAssistant_Mago/js/mago-ui': {
                'Vendor_MagoStockAlert/js/mago-ui-mixin': true
            }
        }
    }
};
```

`view/adminhtml/web/js/mago-ui-mixin.js`:

```js
define([], function () {
    'use strict';

    return function (MagoUI) {
        var el = MagoUI.el;

        MagoUI.register('stockAlert', function (opts) {
            var qty = Number(opts.qty) || 0;
            var href = MagoUI.safeHref(opts.href);
            var title = el(href ? 'a' : 'span', 'vendor-stock-alert-title', opts.title);
            if (href) {
                title.href = href;
            }

            var ask = null;
            if (opts.ask) {
                ask = el('button', 'mago-chip', opts.ask);
                ask.type = 'button';
                ask.setAttribute(MagoUI.sendAttr, opts.ask); // sent as the admin's next message
            }

            return MagoUI.card('vendor-stock-alert', [
                el('div', 'mago-widget-head', [
                    MagoUI.caption(opts.sku ? 'SKU ' + opts.sku : 'Stock', 'is-grow'),
                    MagoUI.badge(qty > 0 ? qty + ' left' : 'Out of stock', qty > 0 ? 'warn' : 'danger')
                ]),
                title,
                ask ? el('div', 'mago-chips', [ask]) : null
            ]);
        });

        return MagoUI;
    };
});
```

A builder gets the spec from the answer as its only argument and returns an `HTMLElement`
(or `null` to show nothing). What `register()` accepts:

- **A new camelCase type.** An existing type, built-in or already registered, is refused with a
  console warning and `register()` returns `false`.
- **A builder function.**

### Helpers

Build with Mago's helpers, so your widget matches the others and stays safe:

| Helper | Use |
|---|---|
| `MagoUI.el(tag, className, children)` | Create an element; `children` can be a string (inserted as text), a node, an array, or `null` |
| `MagoUI.content(value)` | Turn a spec value into a node; text by default, never HTML from an answer |
| `MagoUI.safeHref(href)` | The href when it is a web or in-admin URL, otherwise `null` |
| `MagoUI.card(className, children)` | The white, bordered surface widgets sit on |
| `MagoUI.caption(text, extra)`, `MagoUI.badge(text, tone)`, `MagoUI.icon(name, size, stroke)`, `MagoUI.button(label, className, onClick)`, `MagoUI.chips(items)`, `MagoUI.formatNumber(n)` | The building blocks the built-in widgets use |

The built-in builders are also on `MagoUI` (`MagoUI.stat`, `MagoUI.entityList`, ...), so a widget
can compose them.

### Safety

The spec is written by the model, and the model reads data a customer may have typed (a product
name, an order comment). Treat every spec value as untrusted:

- **Put text in as text.** `el()` and `content()` do this for you. Never use `innerHTML` with spec
  values: the scrub below cleans the HTML your builder returns, but an element you fill through
  `innerHTML` already lives in the admin page while your builder runs.
- **Check links with `MagoUI.safeHref()`.**
- **Never evaluate anything from the spec.**

As a second line of defence, `renderJson()` scrubs whatever a builder returns before it reaches
the page:
- It removes `script`, `style`, `iframe`, `object`, `embed`, `form`, raw-text elements such as
  `noscript` and `xmp`, SVG animation elements and similar.
- It removes every `on…` event attribute and `srcdoc`.
- It removes links and sources that are not web or in-admin URLs.
- It removes inline styles containing `url(`.
- It parses the result once more in an inert document and scrubs that, so what reaches the page
  is what was checked.

A builder that throws is skipped with a console warning; the rest of the answer still renders.

### Interaction

An answer is rendered to static HTML, so event listeners you add in a builder are lost. Use one of
the two attributes the panel handles instead:

| Attribute | Constant | On click |
|---|---|---|
| `data-mago-send="text"` | `MagoUI.sendAttr` | Sends `text` as the admin's next message |
| `data-mago-focus` | `MagoUI.focusAttr` | Moves the focus to the message input |

Put them on a `<button>` so the widget works with the keyboard too. Plain links (`href`) work as
usual.

## 4. Style it

Add a `view/adminhtml/web/css/source/_module.less` to your module; the admin theme compiles it
into its stylesheet. Use the panel's custom properties so the widget follows the configured accent
colour and dark text:

```less
.vendor-stock-alert {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.vendor-stock-alert-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--mago-ink, #373330);
    text-decoration: none;

    &:hover {
        color: var(--mago-accent, #FF7A33);
    }
}
```

Prefix your classes (`vendor-…`) so they never collide with Mago's `mago-…` classes. Reusing
Mago's classes on purpose (`mago-widget-head`, `mago-chips`, `mago-chip`) is fine and keeps your
widget consistent.

## 5. Try it

1. Enable the module and clear caches:
   ```sh
   bin/magento module:enable Vendor_MagoStockAlert
   bin/magento cache:clean config layout
   ```
2. In developer mode, delete the admin's generated `requirejs-config.js` and `css/styles.css` under
   `pub/static/adminhtml/` so they are rebuilt with your mixin and styles. In production mode, run
   `bin/magento setup:static-content:deploy`.
3. Check in the browser console of any admin page that the widget registered:
   ```js
   MagoUI.types.includes('stockAlert') // true
   ```
4. Render a spec by hand to check the layout before involving the model:
   ```js
   document.body.insertAdjacentHTML('beforeend', MagoUI.renderJson(
       '{"type":"stockAlert","title":"Chaz Kangeroo Hoodie","sku":"MH01","qty":2,"ask":"Show the sales of MH01"}'
   ));
   ```
5. Ask Mago a question the widget answers ("Which products are almost out of stock?"). If the model
   does not pick your widget, sharpen the "when to use it" part of the guidance.

## Naming your tool in the panel

A widget often comes with a tool that fetches its data. By default the panel titles a tool after its
name (`stock_alerts` reads "Stock alerts") and shows "Running stock_alerts..." while it runs. To
choose both, implement `MagoAssistant\Mago\Api\Tool\PresentableToolInterface` on the tool:

```php
public function getDisplayName(): string
{
    return 'Stock alerts';
}

public function getStatusMessage(string $action, array $input): ?string
{
    return $action === 'low_stock' ? 'Checking stock levels...' : null; // null keeps the default
}
```

See [skills-architecture.md](skills-architecture.md) for registering the tool itself.

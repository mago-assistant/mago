/*
 * Copyright © Mago Assistant
 */

import {expect, type Page, test} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import ChatMock from 'Actions/backend/ChatMock';
import {widgetAnswer, widgetAnswerWithUnicodeEscapedMarkup} from 'Fixtures/scenarios';

const chatPanel = new ChatPanel();
const chatMock = new ChatMock();

/* The panel is ready once chat-panel.js has read window.MagoUI, so registering after
   openOnDashboard() matches what a module's requirejs mixin does, only later. */
async function registerWidget(page: Page, type: string, builderSource: string): Promise<boolean> {
  return page.evaluate(([widgetType, source]) => {
    const ui = (window as unknown as {MagoUI: {register: (t: string, b: unknown) => boolean}}).MagoUI;
    return ui.register(widgetType, new Function('spec', 'MagoUI', source).bind(null));
  }, [type, builderSource]);
}

test('Renders a widget another module registered', async ({page}) => {
  await chatMock.install(page, widgetAnswer({type: 'stockAlert', sku: 'MH01', qty: 2}));
  await chatPanel.openOnDashboard(page);

  const registered = await registerWidget(page, 'stockAlert',
    'var ui = window.MagoUI; return ui.card("vendor-stock-alert", [ui.el("strong", null, spec.sku), " left: " + spec.qty]);');
  await chatPanel.askAndAwaitReply(page, 'Which products are about to sell out?');

  expect(registered).toBe(true);
  await expect(chatPanel.lastAssistantMessage(page).locator('.vendor-stock-alert')).toHaveText('MH01 left: 2');
});

test('Refuses to register a widget over a built-in type', async ({page}) => {
  await chatMock.install(page, widgetAnswer({type: 'callout', tone: 'info', text: 'Built-in callout'}));
  await chatPanel.openOnDashboard(page);

  const registered = await registerWidget(page, 'callout', 'return window.MagoUI.el("div", "vendor-callout", "mine");');
  await chatPanel.askAndAwaitReply(page, 'Anything I should know?');

  expect(registered).toBe(false);
  await expect(chatPanel.lastAssistantMessage(page).locator('.mago-callout')).toContainText('Built-in callout');
  await expect(chatPanel.lastAssistantMessage(page).locator('.vendor-callout')).toHaveCount(0);
});

test('Scrubs script-bearing markup a registered builder lets through', async ({page}) => {
  const note = '<img src="x" onerror="window.magoXss = true">'
    + '<svg><a><animate attributeName="href" values="javascript:window.magoXss=true"></animate><text>go</text></a></svg>'
    + '<noscript><p title="</noscript><img src=x onerror=window.magoXss=true>"></p></noscript>'
    + '<a class="vendor-link" href="javascript:window.magoXss=true">open</a>';
  await chatMock.install(page, widgetAnswerWithUnicodeEscapedMarkup({type: 'unsafeNote', note}));
  await chatPanel.openOnDashboard(page);

  await registerWidget(page, 'unsafeNote',
    'var node = document.createElement("div"); node.className = "vendor-unsafe-note"; node.innerHTML = spec.note; return node;');
  await chatPanel.askAndAwaitReply(page, 'Show me the note');

  const widget = chatPanel.lastAssistantMessage(page).locator('.vendor-unsafe-note');
  await expect(widget).toBeAttached();
  await expect(widget.locator('[onerror]')).toHaveCount(0);
  await expect(widget.locator('animate')).toHaveCount(0);
  await expect(widget.locator('noscript')).toHaveCount(0);
  await expect(widget.locator('a.vendor-link')).not.toHaveAttribute('href', /javascript:/);
  expect(await page.evaluate(() => (window as unknown as {magoXss?: boolean}).magoXss)).toBeUndefined();
});

test('Skips a widget whose builder throws and still renders the rest of the answer', async ({page}) => {
  await chatMock.install(page, widgetAnswer([
    {type: 'brokenWidget'},
    {type: 'callout', tone: 'info', text: 'Still here'},
  ]));
  await chatPanel.openOnDashboard(page);

  await registerWidget(page, 'brokenWidget', 'throw new Error("broken on purpose");');
  await chatPanel.askAndAwaitReply(page, 'Anything I should know?');

  await expect(chatPanel.lastAssistantMessage(page).locator('.mago-callout')).toContainText('Still here');
});

test('Sends a suggestion chip\'s label as the admin\'s next message', async ({page}) => {
  await chatMock.install(page, widgetAnswer({type: 'suggestions', chips: [{label: 'Export CSV'}]}));
  await chatPanel.openOnDashboard(page);
  await chatPanel.askAndAwaitReply(page, 'Revenue per country');

  const nextRequest = page.waitForRequest(/\/mago\/chat\/stream/);
  await chatPanel.lastAssistantMessage(page).locator('.mago-chip', {hasText: 'Export CSV'}).click();

  expect(JSON.parse((await nextRequest).postData() ?? '{}').message).toBe('Export CSV');
});

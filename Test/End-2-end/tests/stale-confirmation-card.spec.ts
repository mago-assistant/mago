/*
 * Copyright © Mago Assistant
 */

import {expect, test, type Page, type Response} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import {PROVIDER_ROUND_TRIP_TIMEOUT} from 'Config/timeouts';

const chatPanel = new ChatPanel();

const OLDER_CACHE_TYPE = 'config_webservice';
const NEWER_CACHE_TYPE = 'translate';
const STREAM_URL = /\/mago\/chat\/stream/;
const CONFIRM_URL = /\/mago\/chat\/confirm/;
const DONE_EVENT = /event: done\ndata: (\{.*\})/;
const EMPTY_CONFIRM_STREAM = 'event: done\ndata: {}\n\n';

const proposedMessageId = async (response: Response): Promise<number> => {
  await response.finished();

  return JSON.parse((await response.text()).match(DONE_EVENT)?.[1] ?? '{}').message_id;
};

const proposeCacheClean = async (page: Page, cacheType: string): Promise<number> => {
  const stream = page.waitForResponse(STREAM_URL);

  await chatPanel.ask(page, '/cache clean ' + cacheType);
  const messageId = await proposedMessageId(await stream);
  await expect(chatPanel.confirmActions(page)).toHaveCount(1, {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

  return messageId;
};

const confirmDirectly = (page: Page, messageId: number): Promise<string> => page.evaluate(async (id) => {
  const config = (window as any).MAGO_CONFIG;
  const response = await fetch(config.confirmUrl, {
    method: 'POST',
    headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
    body: JSON.stringify({message_id: id, form_key: config.formKey}),
    credentials: 'same-origin',
  });

  return response.text();
}, messageId);

const supersededLines = (page: Page) => page.locator('#mago-messages .mago-line.is-skipped');

/**
 * Issue #332: a proposal the admin moves past is superseded. Its card loses its buttons (live and
 * after a reload), the server refuses to run it, and Allow on the one remaining card confirms
 * exactly the proposal that card shows. The real Stream controller stages both slash writes; only
 * the panel's own confirm request is intercepted, so nothing is cleaned while the test reads which
 * stored proposal the panel asked the server to run.
 */
test.describe('Stale confirmation card', () => {
  test('A new message supersedes the open proposal and Allow confirms only its own card', async ({page}) => {
    await chatPanel.openOnDashboard(page);
    const olderMessageId = await proposeCacheClean(page, OLDER_CACHE_TYPE);
    const newerMessageId = await proposeCacheClean(page, NEWER_CACHE_TYPE);

    expect(newerMessageId, 'the two proposals must be different stored messages').not.toBe(olderMessageId);
    await expect(
      supersededLines(page),
      'the older card must retire into a muted line when the admin moves past it'
    ).toHaveCount(1);

    const refusal = await confirmDirectly(page, olderMessageId);
    expect(refusal, 'the server must refuse to run a superseded proposal').toContain('already been handled');

    await page.reload();
    await expect(chatPanel.confirmActions(page)).toHaveCount(1, {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await expect(
      supersededLines(page),
      'a reloaded conversation must draw the superseded proposal without buttons'
    ).toHaveCount(1);

    await page.route(CONFIRM_URL, (route) => route.fulfill({
      status: 200,
      headers: {'content-type': 'text/event-stream'},
      body: EMPTY_CONFIRM_STREAM,
    }));
    const confirmRequest = page.waitForRequest(CONFIRM_URL);
    await chatPanel.confirmButton(page).click();
    const confirmed = JSON.parse((await confirmRequest).postData() ?? '{}');

    expect(
      confirmed.message_id,
      'Allow on the remaining card must confirm its own proposal ("' + NEWER_CACHE_TYPE + '")'
    ).toBe(newerMessageId);
  });
});

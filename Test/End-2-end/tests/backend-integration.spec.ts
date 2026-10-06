/*
 * Copyright © Mago Assistant
 */

import {expect, test} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import MagentoApi from 'Services/MagentoApi';
import {PROVIDER_ROUND_TRIP_TIMEOUT} from 'Config/timeouts';

const chatPanel = new ChatPanel();
const magentoApi = new MagentoApi();

const IDENTIFIER = 'e2e-wiremock-page';

/**
 * No browser-level mocking here. The request goes through the real controller, ChatService,
 * ACL checks, tool execution and database writes. Only the call to Anthropic is intercepted,
 * by WireMock replaying genuine wire format from Test/End-2-end/wiremock.
 */
test.describe('Backend integration', () => {
  /* Both tests act on the same CMS page, so they cannot share the database concurrently. */
  test.describe.configure({mode: 'serial'});

  test.beforeEach(async ({request}) => {
    await magentoApi.deleteCmsPage(request, IDENTIFIER);
  });

  test.afterEach(async ({request}) => {
    await magentoApi.deleteCmsPage(request, IDENTIFIER);
  });

  test('Creates a real CMS page once the admin confirms', async ({page, request}) => {
    await chatPanel.openOnDashboard(page);
    await chatPanel.ask(page, 'Please run the E2E WireMock check and create the CMS page for it.');

    await expect(chatPanel.toolTags(page)).toHaveText([/cms_data/], {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await expect(chatPanel.confirmButton(page)).toBeVisible({timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await expect(chatPanel.lastAssistantMessage(page)).toContainText(IDENTIFIER, {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    expect(
      await magentoApi.findCmsPage(request, IDENTIFIER),
      'the page must not exist before the admin confirms'
    ).toBeNull();

    await chatPanel.confirmButton(page).click();

    await expect(chatPanel.lastAssistantMessage(page)).toContainText(
      'is live at /' + IDENTIFIER,
      {timeout: PROVIDER_ROUND_TRIP_TIMEOUT}
    );

    const created = await magentoApi.findCmsPage(request, IDENTIFIER);

    expect(created, 'the page was not created').not.toBeNull();
    expect(created.title).toBe('E2E WireMock Page');
    expect(created.active, 'the page was created but is disabled').toBe(true);

    const storefront = await request.get('/' + IDENTIFIER);

    expect(storefront.status(), 'the assistant said the page is live, but it is not reachable').toBe(200);
    expect(await storefront.text()).toContain('Created through the mocked provider.');
  });

  /**
   * A double submit, or the panel and the REST API at once, used to run the same write twice: both
   * requests read the pending flag before either cleared it. Two confirms fired together must
   * leave one running the write and the other told it was already handled.
   */
  test('Runs the write once when the same confirmation arrives twice at once', async ({page, request}) => {
    await chatPanel.openOnDashboard(page);
    await chatPanel.ask(page, 'Please run the E2E WireMock check and create the CMS page for it.');
    await expect(chatPanel.confirmButton(page)).toBeVisible({timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    const responses = await page.evaluate(async () => {
      const config = (window as any).MAGO_CONFIG;
      const post = (url: string, body: Record<string, unknown>) => fetch(url, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
        body: JSON.stringify({...body, form_key: config.formKey}),
        credentials: 'same-origin',
      });

      let messageId = 0;
      for (let attempt = 0; attempt < 20 && !messageId; attempt++) {
        const conversationId = parseInt(sessionStorage.getItem('mago_conv') ?? '0', 10);
        const status = conversationId ? await (await post(config.statusUrl, {conversation_id: conversationId})).json() : {};
        messageId = status.message_id ?? 0;
        if (!messageId) {
          await new Promise((resolve) => setTimeout(resolve, 500));
        }
      }

      const confirm = async () => (await post(config.confirmUrl, {message_id: messageId})).text();
      return Promise.all([confirm(), confirm()]);
    });

    const refused = responses.filter((body) => body.includes('already been handled'));
    const ran = responses.filter((body) => body.includes('event: tool_status'));

    expect(refused, 'exactly one confirm must be refused').toHaveLength(1);
    expect(ran, 'exactly one confirm must run the write').toHaveLength(1);
    expect(await magentoApi.findCmsPage(request, IDENTIFIER)).not.toBeNull();
  });

  test('Makes no database change when the admin rejects', async ({page, request}) => {
    await chatPanel.openOnDashboard(page);
    await chatPanel.ask(page, 'Please run the E2E WireMock check and create the CMS page for it.');

    await expect(chatPanel.rejectButton(page)).toBeVisible({timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await chatPanel.rejectButton(page).click();

    await expect(chatPanel.lastAssistantMessage(page)).toContainText(
      'Action rejected',
      {timeout: PROVIDER_ROUND_TRIP_TIMEOUT}
    );
    expect(await magentoApi.findCmsPage(request, IDENTIFIER)).toBeNull();
  });
});

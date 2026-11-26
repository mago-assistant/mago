/*
 * Copyright © Mago Assistant
 */

import {expect, type Page, test} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import ChatMock from 'Actions/backend/ChatMock';

const chatPanel = new ChatPanel();
const chatMock = new ChatMock();

const CONVERSATION_ID = 4364;
const PROPOSAL_TEXT = 'I will add that note to the order.';

const openStoredProposal = async (page: Page, content: string): Promise<void> => {
  await chatMock.install(page, {
    stream: [],
    history: {conversations: [{entity_id: CONVERSATION_ID, title: 'Order note', updated_at: '2026-01-01 10:00:00'}]},
  });
  await page.route(/\/mago\/chat\/load/, (route) => route.fulfill({
    status: 200,
    headers: {'content-type': 'application/json'},
    body: JSON.stringify({
      entity_id: CONVERSATION_ID,
      title: 'Order note',
      tokens: {},
      messages: [
        {entity_id: 1, role: 'user', content: 'Add a note to order 1001', tool_calls: null, created_at: '2026-01-01 10:00:00'},
        {
          entity_id: 2,
          role: 'assistant',
          content,
          pending_confirmation: 1,
          created_at: '2026-01-01 10:00:01',
          tool_calls: JSON.stringify([{
            id: 'toolu_note_1',
            name: 'order_manager',
            input: {action: 'add_comment', increment_id: '1001', comment: 'Call the customer'},
          }]),
        },
      ],
    }),
  }));
  await chatPanel.openOnDashboard(page);
  await page.locator('#mago-history').click();
  await page.locator('.mago-history-item-title', {hasText: 'Order note'}).click();
};

/**
 * Issue #364: a pending proposal whose reply had text before the card came back from the history
 * with the text and the tool tag, but without Allow / Not now.
 */
test.describe('Pending confirmation card after a reload', () => {
  test('it keeps Allow and Not now when the proposal came with text', async ({page}) => {
    await openStoredProposal(page, PROPOSAL_TEXT);

    // Live, the text and the card share one bubble, without thumbs while the proposal is open.
    const reply = chatPanel.lastAssistantMessage(page);
    await expect(reply).toContainText(PROPOSAL_TEXT);
    await expect(reply.locator('.mago-confirm-actions')).toHaveCount(1);
    await expect(reply.locator('.mago-tool-tag')).toContainText('order_manager');
    await expect(chatPanel.confirmButton(page)).toHaveCount(1);
    await expect(chatPanel.rejectButton(page)).toHaveCount(1);
    await expect(chatPanel.rateButton(page, 'up')).toHaveCount(0);
  });

  test('it keeps Allow and Not now when the proposal came without text', async ({page}) => {
    await openStoredProposal(page, '');

    await expect(chatPanel.confirmButton(page)).toHaveCount(1);
    await expect(chatPanel.rejectButton(page)).toHaveCount(1);
  });
});

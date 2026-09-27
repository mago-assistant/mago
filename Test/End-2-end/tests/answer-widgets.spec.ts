/*
 * Copyright © Mago Assistant
 */

import {expect, test} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import ChatMock, {type ChatScenario, textDeltas} from 'Actions/backend/ChatMock';

const chatPanel = new ChatPanel();
const chatMock = new ChatMock();

const CONVERSATION_ID = 7192;

const answerWith = (answer: string): ChatScenario => ({
  stream: [
    {event: 'conversation', data: {conversation_id: CONVERSATION_ID, admin_user: 'Tester'}},
    ...textDeltas(answer),
    {event: 'done', data: {message_id: 1, conversation_id: CONVERSATION_ID, pending_confirmation: false}},
  ],
});

const stats = (first: string, second: string) =>
  `{"type": "stats", "items": [{"label": "${first}", "value": "1"}, {"label": "${second}", "value": "2"}]}`;

/**
 * Widget specs arrive in ```mago blocks. While a block is still streaming its JSON is incomplete and
 * a skeleton holds its place; once the answer is done the skeleton must be gone (#192).
 */
test.describe('Answer widgets', () => {
  test('it renders a block with several comma-separated widgets as all of them', async ({page}) => {
    await chatMock.install(page, answerWith(
      'Here are the numbers:\n\n```mago\n' + stats('LCP', 'TTFB') + ',\n' + stats('FCP', 'INP') + '\n```'
    ));

    await chatPanel.openOnDashboard(page);
    await chatPanel.askAndAwaitReply(page, 'Show the core web vitals');

    const answer = chatPanel.lastAssistantMessage(page);
    await expect(answer.locator('.mago-stats')).toHaveCount(2);
    await expect(answer.locator('.mago-stat')).toHaveCount(4);
    await expect(answer).toContainText('TTFB');
    await expect(answer.locator('.mago-skeleton')).toHaveCount(0);
  });

  test('it still renders a single widget and a list of widgets', async ({page}) => {
    await chatMock.install(page, answerWith(
      '```mago\n' + stats('Orders', 'Revenue') + '\n```\n\n```mago\n[' + stats('A', 'B') + ', ' + stats('C', 'D') + ']\n```'
    ));

    await chatPanel.openOnDashboard(page);
    await chatPanel.askAndAwaitReply(page, 'Show the sales');

    await expect(chatPanel.lastAssistantMessage(page).locator('.mago-stats')).toHaveCount(3);
  });

  test('it drops an invalid block once the answer is done instead of leaving a skeleton', async ({page}) => {
    await chatMock.install(page, answerWith(
      'Before the widget.\n\n```mago\n{"type": "stats", "items": [\n```\n\nAfter the widget.'
    ));

    await chatPanel.openOnDashboard(page);
    await chatPanel.askAndAwaitReply(page, 'Show something broken');

    const answer = chatPanel.lastAssistantMessage(page);
    await expect(answer).toContainText('After the widget.');
    await expect(answer.locator('.mago-skeleton')).toHaveCount(0);
    await expect(answer).not.toContainText('"type"');
  });
});

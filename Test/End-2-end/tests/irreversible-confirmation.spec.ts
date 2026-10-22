/*
 * Copyright © Mago Assistant
 */

import {expect, test} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import ChatMock from 'Actions/backend/ChatMock';
import {invoiceOrderAfterLongAnswer} from 'Fixtures/scenarios';

const chatPanel = new ChatPanel();
const chatMock = new ChatMock();

/**
 * The acknowledgement checkbox on an irreversible card is visually hidden. When it was positioned
 * against the panel instead of its card, focusing it scrolled the whole panel and left it blank
 * (issue #280).
 */
test('Keeps the panel in place when the irreversible acknowledgement gets focus', async ({page}) => {
  await chatMock.install(page, invoiceOrderAfterLongAnswer);

  await chatPanel.openOnDashboard(page);
  await chatPanel.ask(page, 'Invoice and ship order 000000006');
  await expect(chatPanel.confirmActions(page)).toHaveCount(1);

  await page.locator('#mago-messages .mago-check-input').focus();

  expect(await chatPanel.panel(page).evaluate((panel) => panel.scrollTop)).toBe(0);
  await expect(chatPanel.panel(page).locator('.mago-header')).toBeInViewport();
  await expect(chatPanel.input(page)).toBeInViewport();
});

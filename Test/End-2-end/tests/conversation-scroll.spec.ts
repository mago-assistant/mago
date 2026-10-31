/*
 * Copyright © Mago Assistant
 */

import {expect, Page, test} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import ChatMock from 'Actions/backend/ChatMock';
import {lookupProduct, widgetAnswer} from 'Fixtures/scenarios';

const chatPanel = new ChatPanel();
const chatMock = new ChatMock();

const SHORT_WINDOW = {width: 1280, height: 500};
const EARLIER_QUESTIONS = 4;
const FULLY = 1;
const LONG_PRODUCT_LIST = {
  type: 'entityList',
  items: Array.from({length: 20}, (_, index) => ({title: 'Product ' + (index + 1), meta: 'SKU P' + (index + 1)})),
};

async function openLongConversation(page: Page) {
  await page.setViewportSize(SHORT_WINDOW);
  await chatMock.install(page, lookupProduct);
  await chatPanel.openOnDashboard(page);
  for (let question = 1; question <= EARLIER_QUESTIONS; question++) {
    await chatPanel.askAndAwaitReply(page, 'Which products contain candle?');
  }
  await expect(chatPanel.assistantMessages(page)).toHaveCount(EARLIER_QUESTIONS);
}

test('Keeps the thinking indicator in view when a question is asked at the end of a long conversation', async ({page}) => {
  await openLongConversation(page);
  await page.route(/\/mago\/chat\/stream/, () => {});

  await chatPanel.ask(page, 'And which contain soap?');

  await expect(page.locator('#mago-loading')).toBeVisible();
  await expect(page.locator('#mago-loading')).toBeInViewport({ratio: FULLY});
});

test('Keeps the rating buttons of the latest answer in view in a long conversation', async ({page}) => {
  await openLongConversation(page);

  await chatPanel.askAndAwaitReply(page, 'And which contain soap?');

  await expect(chatPanel.rateButton(page, 'up')).toBeInViewport({ratio: FULLY});
});

test('Leaves the admin at the rows they unfold instead of jumping past them to the end', async ({page}) => {
  await page.setViewportSize(SHORT_WINDOW);
  await chatMock.install(page, widgetAnswer(LONG_PRODUCT_LIST));
  await chatPanel.openOnDashboard(page);
  await chatPanel.askAndAwaitReply(page, 'Which products are low on stock?');
  const showMore = chatPanel.lastAssistantMessage(page).locator('.mago-entities-fold > summary');

  await showMore.click();

  await expect(chatPanel.lastAssistantMessage(page).locator('.mago-entities-fold')).toHaveAttribute('open', '');
  await expect(showMore).toBeInViewport({ratio: FULLY});
});

/*
 * Copyright © Mago Assistant
 */

import {expect, test} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import ChatMock from 'Actions/backend/ChatMock';
import {createCmsPage, createCoupon} from 'Fixtures/scenarios';

const chatPanel = new ChatPanel();
const chatMock = new ChatMock();

test('Asks for confirmation before creating a CMS page and reports the result', async ({page}) => {
  await chatMock.install(page, createCmsPage);

  await chatPanel.openOnDashboard(page);
  await chatPanel.ask(page, 'Create a CMS page titled Summer Sale with url key summer-sale');

  await expect(chatPanel.toolTags(page)).toHaveText([/cms_data/]);
  await expect(chatPanel.confirmActions(page)).toHaveCount(1);
  await expect(chatPanel.lastAssistantMessage(page)).toContainText('cms_data');
  await expect(chatPanel.lastAssistantMessage(page)).toContainText('summer-sale');

  const confirmRequest = page.waitForRequest(/\/mago\/chat\/confirm/);
  await chatPanel.confirmButton(page).click();

  expect(JSON.parse((await confirmRequest).postData() ?? '{}')).toMatchObject({message_id: 100501});
  await expect(chatPanel.confirmActions(page)).toHaveCount(0);
  await expect(chatPanel.lastAssistantMessage(page)).toContainText('is live at /summer-sale');
});

/* #332: the card carries its message id from the confirm event, so no status lookup may decide
   which proposal runs. The lookup used to answer an older card with the newest pending proposal. */
test('Confirms with the id the card carries, without a status lookup', async ({page}) => {
  await chatMock.install(page, createCmsPage);
  const statusCalls = chatMock.countRequestsTo(page, /\/mago\/chat\/status/);

  await chatPanel.openOnDashboard(page);
  await chatPanel.ask(page, 'Create a CMS page titled Summer Sale with url key summer-sale');
  await chatPanel.confirmButton(page).click();

  await expect(chatPanel.confirmActions(page)).toHaveCount(0);
  expect(statusCalls.total()).toBe(0);
});

test('Asks for confirmation before creating a coupon rule', async ({page}) => {
  await chatMock.install(page, createCoupon);

  await chatPanel.openOnDashboard(page);
  await chatPanel.ask(page, 'Add a new coupon code SUMMER20 giving 20% off');

  await expect(chatPanel.toolTags(page)).toHaveText([/coupon_manager/]);
  await expect(chatPanel.lastAssistantMessage(page)).toContainText('SUMMER20');
  await expect(chatPanel.lastAssistantMessage(page)).toContainText('discount_amount');

  await chatPanel.confirmButton(page).click();

  await expect(chatPanel.lastAssistantMessage(page)).toContainText('Created cart price rule');
});

test('Makes no write call when the coupon action is rejected', async ({page}) => {
  await chatMock.install(page, createCoupon);
  const confirmCalls = chatMock.countRequestsTo(page, /\/mago\/chat\/confirm/);

  await chatPanel.openOnDashboard(page);
  await chatPanel.ask(page, 'Add a new coupon code SUMMER20 giving 20% off');
  await chatPanel.rejectButton(page).click();

  await expect(chatPanel.lastAssistantMessage(page)).toContainText('Action rejected. No changes were made.');
  await expect(chatPanel.confirmActions(page)).toHaveCount(0);
  expect(confirmCalls.total()).toBe(0);
});

test('Renders a single set of confirm buttons for one write action', async ({page}) => {
  await chatMock.install(page, createCmsPage);

  await chatPanel.openOnDashboard(page);
  await chatPanel.ask(page, 'Create a CMS page titled Summer Sale with url key summer-sale');

  await expect(chatPanel.confirmButton(page)).toHaveCount(1);
  await expect(chatPanel.rejectButton(page)).toHaveCount(1);
});

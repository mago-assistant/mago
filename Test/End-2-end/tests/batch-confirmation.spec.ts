/*
 * Copyright © Mago Assistant
 */

import {expect, test} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import ChatMock from 'Actions/backend/ChatMock';
import {BATCH_CMS_CONTENT, BATCH_CONFIG_VALUE, batchWrites} from 'Fixtures/scenarios';

const chatPanel = new ChatPanel();
const chatMock = new ChatMock();

/**
 * Several writes proposed in one turn share one card where the admin ticks what runs. Nothing may
 * be ticked for them, and what a row runs has to be readable in full before it is ticked: the one
 * line summary is a reminder, not the review.
 */
test.describe('Batch write confirmation', () => {
  test('Starts with every action unticked and nothing to run', async ({page}) => {
    await chatMock.install(page, batchWrites);

    await chatPanel.openOnDashboard(page);
    await chatPanel.ask(page, 'Update the base link and the home page');

    await expect(chatPanel.batchRows(page)).toHaveCount(2);
    for (const row of await chatPanel.batchRows(page).all()) {
      await expect(row).toHaveAttribute('aria-checked', 'false');
    }
    await expect(chatPanel.confirmButton(page)).toBeDisabled();
  });

  test('Shows every argument of every action in full, as text', async ({page}) => {
    await chatMock.install(page, batchWrites);

    await chatPanel.openOnDashboard(page);
    await chatPanel.ask(page, 'Update the base link and the home page');

    await expect(chatPanel.batchArguments(page)).toHaveCount(2);
    for (const details of await chatPanel.batchArguments(page).all()) {
      await details.locator('summary').click();
    }

    const configArguments = chatPanel.batchArguments(page).nth(0);
    const cmsArguments = chatPanel.batchArguments(page).nth(1);
    await expect(configArguments).toContainText('web/unsecure/base_link_url');
    await expect(configArguments).toContainText(BATCH_CONFIG_VALUE);
    await expect(cmsArguments).toContainText(BATCH_CMS_CONTENT);
    await expect(page.locator('#mago-messages script')).toHaveCount(0);
    expect(await page.title()).not.toBe('owned');
  });

  test('Opening the arguments does not tick the row', async ({page}) => {
    await chatMock.install(page, batchWrites);

    await chatPanel.openOnDashboard(page);
    await chatPanel.ask(page, 'Update the base link and the home page');
    await chatPanel.batchArguments(page).nth(0).locator('summary').click();

    await expect(chatPanel.batchRows(page).nth(0)).toHaveAttribute('aria-checked', 'false');
    await expect(chatPanel.confirmButton(page)).toBeDisabled();
  });

  test('Runs only the ticked action, once, even on a double click', async ({page}) => {
    await chatMock.install(page, batchWrites);
    const confirmCalls = chatMock.countRequestsTo(page, /\/mago\/chat\/confirm/);

    await chatPanel.openOnDashboard(page);
    await chatPanel.ask(page, 'Update the base link and the home page');
    await chatPanel.batchRows(page).nth(1).click();
    await expect(chatPanel.confirmButton(page)).toBeEnabled();

    const confirmRequest = page.waitForRequest(/\/mago\/chat\/confirm/);
    await chatPanel.confirmButton(page).dblclick();

    expect(JSON.parse((await confirmRequest).postData() ?? '{}')).toMatchObject({tool_call_ids: ['toolu_cms_2']});
    await expect(chatPanel.lastAssistantMessage(page)).toContainText('Updated the home page.');
    expect(confirmCalls.total()).toBe(1);
  });
});

/*
 * Copyright © Mago Assistant
 */

import {expect, test} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import ChatMock from 'Actions/backend/ChatMock';
import {
  CHAINED_MESSAGE_ID,
  batchWritesWithCaution,
  chainedIrreversibleWrite,
  irreversibleWrite,
} from 'Fixtures/scenarios';

const chatPanel = new ChatPanel();
const chatMock = new ChatMock();

/**
 * The server only runs a write that cannot be undone, or that carries a caution, when the confirm
 * request says its acknowledgement was ticked. These check that both cards ask for the tick and
 * send it, so such a confirm never reaches the server without it.
 */
test.describe('Confirmation acknowledgement', () => {
  test('Sends the ticked acknowledgement of a write that cannot be undone', async ({page}) => {
    await chatMock.install(page, irreversibleWrite);

    await chatPanel.openOnDashboard(page);
    await chatPanel.ask(page, 'Cancel order 000000100');
    await expect(chatPanel.confirmButton(page)).toBeDisabled();
    await chatPanel.acknowledgement(page).click();

    const confirmRequest = page.waitForRequest(/\/mago\/chat\/confirm/);
    await chatPanel.confirmButton(page).click();

    expect(JSON.parse((await confirmRequest).postData() ?? '{}')).toMatchObject({acknowledged: true});
    await expect(chatPanel.lastAssistantMessage(page)).toContainText('Order 000000100 is canceled.');
  });

  test('Asks for the acknowledgement once a cautioned write in a batch is ticked', async ({page}) => {
    await chatMock.install(page, batchWritesWithCaution);

    await chatPanel.openOnDashboard(page);
    await chatPanel.ask(page, 'Update the base link and the home page');
    await chatPanel.batchRows(page).nth(0).click();

    await expect(chatPanel.acknowledgement(page)).toBeVisible();
    await expect(chatPanel.confirmButton(page)).toBeDisabled();
  });

  test('Sends the ticked acknowledgement with the ticked calls of a batch', async ({page}) => {
    await chatMock.install(page, batchWritesWithCaution);

    await chatPanel.openOnDashboard(page);
    await chatPanel.ask(page, 'Update the base link and the home page');
    await chatPanel.batchRows(page).nth(0).click();
    await chatPanel.acknowledgement(page).click();

    const confirmRequest = page.waitForRequest(/\/mago\/chat\/confirm/);
    await chatPanel.confirmButton(page).click();

    expect(JSON.parse((await confirmRequest).postData() ?? '{}')).toMatchObject({
      acknowledged: true,
      tool_call_ids: ['toolu_config_1'],
    });
  });

  test('Runs the reversible write of a batch without the acknowledgement', async ({page}) => {
    await chatMock.install(page, batchWritesWithCaution);

    await chatPanel.openOnDashboard(page);
    await chatPanel.ask(page, 'Update the base link and the home page');
    await chatPanel.batchRows(page).nth(1).click();
    await expect(chatPanel.confirmButton(page)).toBeEnabled();

    const confirmRequest = page.waitForRequest(/\/mago\/chat\/confirm/);
    await chatPanel.confirmButton(page).click();

    const body = JSON.parse((await confirmRequest).postData() ?? '{}');
    expect(body).toMatchObject({tool_call_ids: ['toolu_cms_2']});
    expect(body).not.toHaveProperty('acknowledged');
  });

  test('Asks for the acknowledgement of a write the follow-up turn proposes', async ({page}) => {
    await chatMock.install(page, chainedIrreversibleWrite);

    await chatPanel.openOnDashboard(page);
    await chatPanel.ask(page, 'Update the home page, then cancel order 000000100');
    await chatPanel.confirmButton(page).click();
    await expect(chatPanel.acknowledgement(page)).toBeVisible();
    await expect(chatPanel.confirmButton(page)).toBeDisabled();
    await chatPanel.acknowledgement(page).click();

    const confirmRequest = page.waitForRequest(/\/mago\/chat\/confirm/);
    await chatPanel.confirmButton(page).click();

    expect(JSON.parse((await confirmRequest).postData() ?? '{}')).toMatchObject({
      message_id: CHAINED_MESSAGE_ID,
      acknowledged: true,
    });
  });
});

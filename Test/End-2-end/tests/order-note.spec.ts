/*
 * Copyright © Mago Assistant
 */

import {expect, test} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import MagentoApi from 'Services/MagentoApi';
import {PROVIDER_ROUND_TRIP_TIMEOUT} from 'Config/timeouts';

const chatPanel = new ChatPanel();
const magentoApi = new MagentoApi();

const ORDER_NUMBER = '000000001';
const NOTE = 'E2E in-process order note';

/**
 * No browser-level mocking: the confirmed tool call looks the order up with an order search and adds
 * the note through the order comments route, both run in-process by the internal API client as the
 * logged-in admin. Only the provider is played by WireMock (wiremock/mappings/order-note.json).
 */
test.describe('Order note', () => {
  test('Adds a note to an order found by its order number once the admin confirms', async ({page, request}) => {
    const order = await magentoApi.findOrderByIncrementId(request, ORDER_NUMBER);
    test.skip(order === null, 'this install has no order ' + ORDER_NUMBER);
    const notesBefore = countNotes(order);

    await chatPanel.openOnDashboard(page);
    await chatPanel.ask(page, 'Please run the E2E order note check.');

    await expect(chatPanel.toolTags(page)).toHaveText([/order_manager/], {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await expect(chatPanel.confirmButton(page)).toBeVisible({timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await chatPanel.confirmButton(page).click();

    await expect(chatPanel.lastAssistantMessage(page)).toContainText(
      'Order note check complete',
      {timeout: PROVIDER_ROUND_TRIP_TIMEOUT}
    );

    const updated = await magentoApi.findOrderByIncrementId(request, ORDER_NUMBER);

    expect(countNotes(updated), 'the note was not added to the order').toBe(notesBefore + 1);
  });
});

function countNotes(order: {status_histories?: Array<{comment?: string}>}): number {
  return (order.status_histories ?? []).filter((history) => history.comment === NOTE).length;
}

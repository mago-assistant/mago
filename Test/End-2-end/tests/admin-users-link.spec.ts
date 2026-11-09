/*
 * Copyright © Mago Assistant
 */

import {expect, test} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import {PROVIDER_ROUND_TRIP_TIMEOUT} from 'Config/timeouts';

const chatPanel = new ChatPanel();

/**
 * The request runs through the real controller, ChatService and admin_navigator; only the
 * provider is WireMock (wiremock/mappings/admin-users-link.json). The mocked model looks the
 * "Admin Users" page up and answers with the url token the tool returned, which the panel
 * rehydrates into the real, secret-key-bearing admin link. Clicking that link has to land on the
 * admin users grid: a key signed for the wrong route name is refused by Magento on arrival and
 * sends the admin to the dashboard instead (#303).
 */
test.describe('Admin users link', () => {
  test('The Admin Users link admin_navigator hands out opens the admin users grid', async ({page}) => {
    await chatPanel.openOnDashboard(page);
    await chatPanel.ask(page, 'Please run the E2E admin users link check.');

    await expect(chatPanel.toolTags(page)).toHaveText([/admin_navigator/], {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await expect(chatPanel.lastAssistantMessage(page))
      .toContainText('Admin users are managed here', {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await expect(chatPanel.lastAssistantMessage(page)).not.toContainText('not offered');

    const link = chatPanel.lastAssistantMessage(page).locator('a[href*="/admin/user/index/"]');
    await expect(link).toBeVisible({timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await expect(link).toHaveAttribute('href', /\/key\/[0-9a-f]+\//);

    await link.click();

    await expect(page).toHaveURL(/\/admin\/user\/index\//, {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await expect(page).not.toHaveURL(/\/admin\/dashboard\//);
    await expect(page.locator('h1.page-title')).toHaveText('Users');
  });
});

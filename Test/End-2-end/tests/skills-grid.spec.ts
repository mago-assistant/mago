/*
 * Copyright © Mago Assistant
 */

import {expect, test} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';

const chatPanel = new ChatPanel();

/**
 * Issue #304: the Skills & Permissions grid describes a skill to the administrator, not to the model
 */
test('it shows the first sentence of a skill without its action list', async ({page}) => {
  const items = await chatPanel.openSkillsGrid(page);
  const cmsData = items.find((item: any) => item.name === 'cms_data');

  expect(cmsData).toBeDefined();
  expect(cmsData.description).toBe('Manage CMS pages and blocks.');
});

test('it shows the admin description of a skill that declares one', async ({page}) => {
  const items = await chatPanel.openSkillsGrid(page);
  const orderManager = items.find((item: any) => item.name === 'order_manager');

  expect(orderManager).toBeDefined();
  expect(orderManager.description).toBe('Look up and manage orders, invoices, shipments and credit memos.');
});

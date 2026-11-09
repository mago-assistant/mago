/*
 * Copyright © Mago Assistant
 */

import {expect, test, type Page} from '@playwright/test';
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

/**
 * Issue #307 and #308: the skills grid sorts, searches and has a toolbar
 */
const isSortedByName = (names: string[], direction: 'asc' | 'desc'): boolean => {
  const sorted = [...names].sort((left, right) => left.localeCompare(right, 'en', {numeric: true}));

  return JSON.stringify(direction === 'asc' ? sorted : sorted.reverse()) === JSON.stringify(names);
};

const renderedWith = (page: Page, parameter: string, value: string) => page.waitForResponse(
  (response) => response.url().includes('mui/index/render')
    && new URL(response.url()).searchParams.get(parameter) === value
);

test('it lists the skills sorted by name', async ({page}) => {
  const items = await chatPanel.openSkillsGrid(page);

  expect(isSortedByName(items.map((item: any) => item.name), 'asc')).toBe(true);
});

test('it sorts the skills by name descending after clicking the header', async ({page}) => {
  await chatPanel.openSkillsGrid(page);
  const rendered = renderedWith(page, 'sorting[direction]', 'desc');

  await page.locator('th.data-grid-th._sortable', {hasText: 'Name'}).click();
  const body = await (await rendered).json();

  expect(isSortedByName(body.items.map((item: any) => item.name), 'desc')).toBe(true);
});

test('it finds a skill by keyword', async ({page}) => {
  await chatPanel.openSkillsGrid(page);
  const rendered = renderedWith(page, 'search', 'credit memos');

  await page.locator('#fulltext').fill('credit memos');
  await page.locator('#fulltext').press('Enter');
  const body = await (await rendered).json();

  expect(body.items.map((item: any) => item.name)).toEqual(['order_manager']);
  expect(body.totalRecords).toBe(1);
});

test('it shows the toolbar with the record count above the skills', async ({page}) => {
  const items = await chatPanel.openSkillsGrid(page);

  await expect(page.locator('.admin__data-grid-header .admin__control-support-text').first())
    .toHaveText(new RegExp('^\\s*' + items.length + ' records found\\s*$'));
});

test('it shows only the skills of the category picked in the filters', async ({page}) => {
  await chatPanel.openSkillsGrid(page);
  const rendered = renderedWith(page, 'filters[category]', 'Sales');

  await page.locator('button[data-action="grid-filter-expand"]').click();
  await page.locator('.admin__data-grid-filters select[name="category"]').selectOption('Sales');
  await page.locator('button[data-action="grid-filter-apply"]').click();
  const body = await (await rendered).json();

  expect(body.items.length).toBeGreaterThan(0);
  expect(body.items.every((item: any) => item.category === 'Sales')).toBe(true);
});

test('it does not match a keyword against the type or assigned users of a skill', async ({page}) => {
  await chatPanel.openSkillsGrid(page);
  const rendered = renderedWith(page, 'search', 'write');

  await page.locator('#fulltext').fill('write');
  await page.locator('#fulltext').press('Enter');
  const body = await (await rendered).json();

  expect(body.items.every((item: any) => [item.name, item.description, item.category].join(' ').toLowerCase().includes('write'))).toBe(true);
});

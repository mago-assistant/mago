/*
 * Copyright © Mago Assistant
 */

import {expect, test} from '@playwright/test';
import {readFile} from 'node:fs/promises';
import ChatPanel from 'Pages/backend/ChatPanel';
import ChatMock from 'Actions/backend/ChatMock';
import {EXPORT_CONVERSATION_ID, lowStockRead} from 'Fixtures/scenarios';
import {PROVIDER_ROUND_TRIP_TIMEOUT} from 'Config/timeouts';

const chatPanel = new ChatPanel();
const chatMock = new ChatMock();

/**
 * Issue #298: a read-only lookup's full result downloads as CSV or Excel from a row under the
 * answer. The first tests replay the stream; the last one goes through the real backend, with only
 * the provider mocked by WireMock, and downloads the files.
 */
test.describe('Download the full result of a read', () => {
  test('Offers the full list as CSV and Excel under the answer', async ({page}) => {
    await chatMock.install(page, lowStockRead(true));
    await chatPanel.openOnDashboard(page);

    await chatPanel.askAndAwaitReply(page, 'Which products are running low on stock?');

    await expect(chatPanel.lastAssistantMessage(page).locator('.mago-tool-status')).toContainText('Checked low stock');
    await expect(chatPanel.exportRows(page)).toHaveCount(1);
    await expect(chatPanel.exportRows(page).locator('.mago-download-title')).toHaveText('Full low stock list');
    const links = chatPanel.exportLinks(page);
    await expect(links).toHaveText(['CSV', 'Excel']);
    await expect(links.first()).toHaveAttribute('title', 'All 23 rows as CSV');
    await expect(links.first()).toHaveAttribute('aria-label', 'Download Full low stock list as CSV');
    for (const [index, format] of ['csv', 'xlsx'].entries()) {
      const href = new URL(await links.nth(index).getAttribute('href') ?? '', page.url());
      expect(href.pathname).toContain('/mago/chat/export/');
      expect(href.searchParams.get('conversation_id')).toBe(String(EXPORT_CONVERSATION_ID));
      expect(href.searchParams.get('tool_call_id')).toBe('toolu_low_1');
      expect(href.searchParams.get('format')).toBe(format);
    }
  });

  test('Puts the download right under the table the answer drew', async ({page}) => {
    const table = {
      type: 'table',
      columns: [{key: 'sku', label: 'SKU'}, {key: 'qty', label: 'Qty', align: 'right'}],
      rows: [{sku: 'MH01', qty: '0'}, {sku: 'MH02', qty: '0'}],
    };
    await chatMock.install(page, lowStockRead(true, false, 'Two of them:\n\n```mago\n' + JSON.stringify(table) + '\n```\n\nThe rest sit on variants.'));
    await chatPanel.openOnDashboard(page);

    await chatPanel.askAndAwaitReply(page, 'Which products are running low on stock?');

    const row = chatPanel.lastAssistantMessage(page).locator('.mago-table + .mago-download-row.is-attached');
    await expect(row).toHaveCount(1);
    await expect(row.locator('.mago-download-title')).toHaveText('All 23 rows');
    await expect(row.locator('.mago-download-link')).toHaveText(['CSV', 'Excel']);
    await expect(chatPanel.lastAssistantMessage(page).locator('.mago-download-rows')).toHaveCount(0);
  });

  test('Turns the "more not shown" note of a product list into the download', async ({page}) => {
    const list = {type: 'entityList', items: [{title: 'Chaz Kangeroo Hoodie', meta: 'SKU MH01'}], more: {count: 22}};
    await chatMock.install(page, lowStockRead(true, false, 'The first one:\n\n```mago\n' + JSON.stringify(list) + '\n```'));
    await chatPanel.openOnDashboard(page);

    await chatPanel.askAndAwaitReply(page, 'Which products are running low on stock?');

    const entities = chatPanel.lastAssistantMessage(page).locator('.mago-entities');
    await expect(entities.locator('.mago-entity-more.is-note')).toHaveCount(0);
    await expect(entities.locator('.mago-download-row.is-attached .mago-download-title')).toHaveText('22 more not shown');
    await expect(entities.locator('.mago-download-link').first()).toHaveAttribute('title', 'All 23 rows as CSV');
  });

  test('Offers no download for a lookup the server did not mark as exportable', async ({page}) => {
    await chatMock.install(page, lowStockRead(false));
    await chatPanel.openOnDashboard(page);

    await chatPanel.askAndAwaitReply(page, 'Which products are running low on stock?');

    await expect(chatPanel.lastAssistantMessage(page).locator('.mago-tool-status')).toHaveCount(1);
    await expect(chatPanel.exportRows(page)).toHaveCount(0);
  });

  /* A turn that ends waiting for a write stores only the write, so the read cannot be found to export */
  test('Offers no download on a turn that ends waiting for a confirmation', async ({page}) => {
    await chatMock.install(page, lowStockRead(true, true));
    await chatPanel.openOnDashboard(page);

    await chatPanel.ask(page, 'Which products are running low on stock? Then disable them.');

    await expect(chatPanel.lastAssistantMessage(page).locator('.mago-tool-status.is-done')).toHaveCount(1);
    await expect(chatPanel.exportRows(page)).toHaveCount(0);
  });

  test('Shows why a download was refused without leaving the page', async ({page}) => {
    await chatMock.install(page, lowStockRead(true));
    await page.route(/\/mago\/chat\/export\//, (route) => route.fulfill({
      status: 403,
      headers: {'content-type': 'application/json'},
      body: JSON.stringify({error: 'Access denied: you do not have the required Magento permission'}),
    }));
    await chatPanel.openOnDashboard(page);
    await chatPanel.askAndAwaitReply(page, 'Which products are running low on stock?');
    const before = page.url();

    await chatPanel.exportLinks(page).first().click();

    await expect(chatPanel.lastAssistantMessage(page).locator('.mago-download-error'))
      .toContainText('Access denied: you do not have the required Magento permission');
    expect(page.url()).toBe(before);
  });

  test('Keeps the download under the answer after the page is reloaded', async ({page}) => {
    await chatMock.install(page, lowStockRead(true));
    await page.route(/\/mago\/chat\/load/, (route) => route.fulfill({
      status: 200,
      headers: {'content-type': 'application/json'},
      body: JSON.stringify({
        entity_id: EXPORT_CONVERSATION_ID,
        title: 'Low stock',
        tokens: {},
        messages: [
          {entity_id: 1, role: 'user', content: 'Which products are running low on stock?', tool_calls: null},
          {
            entity_id: 2,
            role: 'assistant',
            content: '50 products came back at or below 5 in stock.',
            tool_calls: JSON.stringify([{
              id: 'toolu_low_1',
              name: 'product_data',
              input: {action: 'low_stock'},
              status: 'done',
              status_message: 'Checking low stock...',
              status_done_message: 'Checked low stock',
              status_duration_ms: 12,
              exportable: true,
              export_title: 'Full low stock list',
              export_row_count: 23,
            }]),
          },
        ],
      }),
    }));
    await chatPanel.openOnDashboard(page);
    await chatPanel.askAndAwaitReply(page, 'Which products are running low on stock?');

    await page.reload();

    await expect(chatPanel.lastAssistantMessage(page).locator('.mago-tool-status')).toContainText('Checked low stock');
    await expect(chatPanel.exportRows(page).locator('.mago-download-title')).toHaveText('Full low stock list');
    await expect(chatPanel.exportLinks(page)).toHaveText(['CSV', 'Excel']);
    const href = new URL(await chatPanel.exportLinks(page).first().getAttribute('href') ?? '', page.url());
    expect(href.searchParams.get('tool_call_id')).toBe('toolu_low_1');
  });

  test('Downloads every low stock row as CSV and Excel from the real backend without leaving the page', async ({page}) => {
    await chatPanel.openOnDashboard(page);
    await chatPanel.ask(page, 'Please run the E2E low stock export check.');
    await expect(chatPanel.exportLinks(page)).toHaveText(['CSV', 'Excel'], {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await expect(chatPanel.exportLinks(page).first()).toHaveAttribute('title', /^All [\d,.]+ rows as CSV$/);
    const before = page.url();

    const [csvDownload] = await Promise.all([page.waitForEvent('download'), chatPanel.exportLinks(page).first().click()]);
    expect(csvDownload.suggestedFilename()).toMatch(/^product-data-low-stock-\d{4}-\d{2}-\d{2}\.csv$/);
    const lines = (await readFile(await csvDownload.path())).toString('utf8').replace(/^﻿/, '').trim().split('\n');
    expect(lines[0]).toBe('SKU,Product name,Quantity,Price');
    expect(lines.length, 'the file holds more rows than the five the answer asked for').toBeGreaterThan(6);

    const [xlsxDownload] = await Promise.all([page.waitForEvent('download'), chatPanel.exportLinks(page).last().click()]);
    expect(xlsxDownload.suggestedFilename()).toMatch(/\.xlsx$/);
    expect((await readFile(await xlsxDownload.path())).subarray(0, 2).toString()).toBe('PK');

    expect(page.url()).toBe(before);
  });
});

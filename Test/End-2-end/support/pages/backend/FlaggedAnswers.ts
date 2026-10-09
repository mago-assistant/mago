/*
 * Copyright © Mago Assistant
 */

import {type Download, type Locator, type Page} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';

/**
 * Mago Assistant > Answer Feedback, and the view of one piece of feedback. Admin URLs carry a secret key, so the
 * view is reached through ChatPanel.keyedUrlFor(), which borrows a key from the grid.
 */
export default class FlaggedAnswers {
  private readonly chatPanel = new ChatPanel();

  async openGrid(page: Page) {
    const url = await this.chatPanel.keyedUrlFor(page, 'mago/flags/index');

    await page.goto(url, {waitUntil: 'load'});
  }

  gridRows(page: Page): Locator {
    return page.locator('.admin__data-grid-wrap tbody tr.data-row');
  }

  async search(page: Page, keyword: string) {
    const input = page.locator('.admin__data-grid-header .data-grid-search-control');

    await input.fill(keyword);
    await input.press('Enter');
  }

  /**
   * The grid remembers its keyword per admin (ui_bookmark), and the specs share one admin, so a
   * spec that searched leaves the grid unfiltered again for the next one.
   */
  async clearSearch(page: Page) {
    await this.search(page, '');
  }

  /**
   * "Select All" sends the grid's filters and an exclusion list instead of ids, which is exactly
   * what MassDelete has to resolve through Magento's Filter.
   */
  async deleteAllMatching(page: Page) {
    /* The select-all menu sits in the grid's own header cell; the Actions menu in the toolbar. The
       sticky header clones both once the page scrolls, hence first(). */
    const multicheck = page.locator('.admin__data-grid-wrap .data-grid-multicheck-cell').first();
    await multicheck.locator('.action-multicheck-toggle').click();
    await multicheck.locator('.action-menu-item').getByText('Select All', {exact: true}).click();

    const actions = page.locator('.admin__data-grid-header .action-select-wrap').first();
    await actions.locator('.action-select').click();
    await actions.locator('.action-menu-item').getByText('Delete', {exact: true}).click();
    await page.locator('.modal-popup.confirm._show .action-accept').click();
    await page.waitForURL(/\/mago\/flags\/index\//);
  }

  errorMessage(page: Page, text: string): Locator {
    return page.locator('.message-error').filter({hasText: text});
  }

  async openFlag(page: Page, flagId: number) {
    const url = await this.chatPanel.keyedUrlFor(page, 'mago/flags/view/id/' + flagId);

    await page.goto(url, {waitUntil: 'load'});
  }

  content(page: Page): Locator {
    return page.locator('.mago-flag-view');
  }

  status(page: Page): Locator {
    return page.locator('.mago-flag-status');
  }

  rating(page: Page): Locator {
    return page.locator('.mago-flag-rating');
  }

  /**
   * Picked by its text, because the page can show a message that is not the one just added. The CI
   * images keep sessions in the database, which takes no lock: a request still running from the
   * previous page (the notification area render) can read the session before the next page took its
   * message out, and write it back after, so that message shows up again on a later page.
   */
  successMessage(page: Page, text: string): Locator {
    return page.locator('.message-success').filter({hasText: text});
  }

  async resolve(page: Page) {
    await page.getByRole('button', {name: 'Mark as resolved'}).click();
  }

  async reopen(page: Page) {
    await page.getByRole('button', {name: 'Reopen'}).click();
  }

  async download(page: Page): Promise<Download> {
    const download = page.waitForEvent('download');
    await page.getByRole('button', {name: 'Download JSON'}).click();

    return download;
  }

  async delete(page: Page) {
    await page.getByRole('button', {name: 'Delete'}).click();
    await page.locator('.modal-popup.confirm._show .action-accept').click();
    await page.waitForURL(/\/mago\/flags\/index\//);
  }
}

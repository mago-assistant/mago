/*
 * Copyright © Mago Assistant
 */

import {expect, test} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import ChatMock from 'Actions/backend/ChatMock';
import {manyReads} from 'Fixtures/scenarios';

const chatPanel = new ChatPanel();
const chatMock = new ChatMock();

/**
 * Issue #312: a turn with many reads shows its first five tags and trace lines and folds the
 * rest behind a toggle. A failed line is never folded.
 */
test.describe('Fold long tool call lists', () => {
  test('Shows five of each and opens the rest on demand', async ({page}) => {
    await chatMock.install(page, manyReads(8, 7));
    await chatPanel.openOnDashboard(page);

    await chatPanel.askAndAwaitReply(page, 'Revenue per month this year?');

    const message = chatPanel.lastAssistantMessage(page);
    const tags = message.locator('.mago-tool-tags > .mago-tool-tag:not(.mago-tool-more)');
    const lines = message.locator('.mago-tool-trace > .mago-tool-status');
    const visibleTags = message.locator('.mago-tool-tags > .mago-tool-tag:not(.mago-tool-more):visible');
    const visibleLines = message.locator('.mago-tool-trace > .mago-tool-status:visible');
    const tagToggle = message.locator('.mago-tool-tags > .mago-tool-more');
    const lineToggle = message.locator('.mago-tool-trace > .mago-tool-more');

    await expect(tags).toHaveCount(8);
    await expect(visibleTags).toHaveCount(5);
    await expect(tagToggle).toHaveText('+3');

    // 8 lines: 5 shown, the failed 7th stays, so 2 fold
    await expect(visibleLines).toHaveCount(6);
    await expect(lines.nth(6)).toBeVisible();
    await expect(lines.nth(6)).toHaveClass(/is-failed/);
    await expect(lineToggle).toHaveText('…and 2 more tool calls');
    await expect(lineToggle).toHaveAttribute('aria-expanded', 'false');

    await lineToggle.click();
    await expect(visibleTags).toHaveCount(8);
    await expect(visibleLines).toHaveCount(8);
    await expect(tagToggle).toHaveCount(0);
    await expect(lineToggle).toHaveText('Show less');
    await expect(lineToggle).toHaveAttribute('aria-expanded', 'true');

    await lineToggle.click();
    await expect(visibleLines).toHaveCount(6);

    await tagToggle.focus();
    await page.keyboard.press('Enter');
    await expect(visibleTags).toHaveCount(8);
    await expect(visibleLines).toHaveCount(8);
    await expect(lineToggle).toBeFocused();
  });

  test('Draws no toggle for five calls or fewer', async ({page}) => {
    await chatMock.install(page, manyReads(5));
    await chatPanel.openOnDashboard(page);

    await chatPanel.askAndAwaitReply(page, 'Revenue per month this year?');

    const message = chatPanel.lastAssistantMessage(page);
    await expect(message.locator('.mago-tool-trace > .mago-tool-status')).toHaveCount(5);
    await expect(message.locator('.mago-tool-more')).toHaveCount(0);
    await expect(message.locator('.is-folded')).toHaveCount(0);
  });
});

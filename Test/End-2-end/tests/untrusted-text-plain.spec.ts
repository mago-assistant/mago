/*
 * Copyright © Mago Assistant
 */

import {expect, type Locator, type Page, test} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import ChatMock from 'Actions/backend/ChatMock';
import {stageFieldValue, vaultAnswer} from 'Fixtures/scenarios';

const chatPanel = new ChatPanel();
const chatMock = new ChatMock();

/* What a customer can write into a review: markdown, a link off site, script and a heading. Shown
   anywhere in the admin, it has to read exactly like this, character for character. Kept under
   the 80 characters a confirmation card previews of a value. */
const HOSTILE_TEXT = '**bold** [click](https://evil.example) <img src=x onerror=magoXss=1> # heading';
const REVIEW_TOKEN = 'mago://reviewtext_1';
const URL_TOKEN = 'mago://url_1';
const REVIEW_URL = '/admin/review/product/edit/id/1/';

async function wasScriptRun(page: Page): Promise<boolean> {
  return page.evaluate(() => (window as unknown as {magoXss?: unknown}).magoXss !== undefined);
}

async function expectShownLiterally(message: Locator) {
  await expect(message).toContainText('**bold** [click](https://evil.example) <img src=x');
  await expect(message).toContainText('# heading');
  await expect(message.locator('strong')).toHaveCount(0);
  await expect(message.locator('a[href*="evil.example"]')).toHaveCount(0);
  await expect(message.locator('img')).toHaveCount(0);
  await expect(message.locator('h1')).toHaveCount(0);
}

/**
 * The model's answer is the one thing rendered as markdown. What it quotes from the store (a review,
 * a name) reaches the browser as a privacy token with its value beside it, and goes into the
 * rendered answer as text, after sanitizing, so a value can never become markup.
 */
test.describe('Customer text in an answer', () => {
  test('it shows a review full of markup exactly as the customer wrote it', async ({page}) => {
    await chatMock.install(page, vaultAnswer(['The latest review says: ', REVIEW_TOKEN], {[REVIEW_TOKEN]: HOSTILE_TEXT}));
    await chatPanel.openOnDashboard(page);
    await chatPanel.askAndAwaitReply(page, 'What does the latest review say?');

    const answer = chatPanel.lastAssistantMessage(page);
    await expect(answer).toContainText('The latest review says:');
    await expectShownLiterally(answer);
    await expect(answer).not.toContainText(REVIEW_TOKEN);
    expect(await wasScriptRun(page)).toBe(false);
  });

  test('it shows a review quoted in a code span as text too', async ({page}) => {
    await chatMock.install(page, vaultAnswer(['Quoted: `' + REVIEW_TOKEN + '`'], {[REVIEW_TOKEN]: HOSTILE_TEXT}));
    await chatPanel.openOnDashboard(page);
    await chatPanel.askAndAwaitReply(page, 'Quote the latest review');

    const answer = chatPanel.lastAssistantMessage(page);
    await expect(answer.locator('code')).toContainText('**bold** [click](https://evil.example) <img src=x');
    await expect(answer.locator('img')).toHaveCount(0);
  });

  test('it never turns a customer value into a link target', async ({page}) => {
    await chatMock.install(page, vaultAnswer(['See [the review](' + REVIEW_TOKEN + ')'], {[REVIEW_TOKEN]: 'https://evil.example/phish'}));
    await chatPanel.openOnDashboard(page);
    await chatPanel.askAndAwaitReply(page, 'Where is the review?');

    const answer = chatPanel.lastAssistantMessage(page);
    await expect(answer).toContainText('the review');
    await expect(answer.locator('a')).toHaveCount(0);
  });

  test('a suggestion chip sends the token, never the customer text behind it', async ({page}) => {
    const chip = '```mago\n{"type":"suggestions","chips":[{"label":"Reply to ' + REVIEW_TOKEN + '"}]}\n```';
    await chatMock.install(page, vaultAnswer(['Next step?\n\n' + chip], {[REVIEW_TOKEN]: HOSTILE_TEXT}));
    await chatPanel.openOnDashboard(page);
    await chatPanel.askAndAwaitReply(page, 'What does the latest review say?');

    const button = chatPanel.lastAssistantMessage(page).locator('[data-mago-send]');
    await expect(button).toContainText('**bold** [click]');
    await expect(button).toHaveAttribute('data-mago-send', 'Reply to ' + REVIEW_TOKEN);
  });

  test('it still links an admin url the server built', async ({page}) => {
    await chatMock.install(page, vaultAnswer(['[Open the review](' + URL_TOKEN + ')'], {[URL_TOKEN]: REVIEW_URL}));
    await chatPanel.openOnDashboard(page);
    await chatPanel.askAndAwaitReply(page, 'Where is the review?');

    const link = chatPanel.lastAssistantMessage(page).locator('a', {hasText: 'Open the review'});
    await expect(link).toHaveAttribute('href', REVIEW_URL);
    await expect(link).toHaveAttribute('target', '_self');
  });

  test('it shows a review in a widget as text and keeps its admin link', async ({page}) => {
    const widget = {type: 'entityList', items: [{title: REVIEW_TOKEN, href: URL_TOKEN}]};
    await chatMock.install(page, vaultAnswer(
      ['Here it is.\n\n```mago\n' + JSON.stringify(widget) + '\n```\n'],
      {[REVIEW_TOKEN]: HOSTILE_TEXT, [URL_TOKEN]: REVIEW_URL}
    ));
    await chatPanel.openOnDashboard(page);
    await chatPanel.askAndAwaitReply(page, 'Show the latest review');

    const row = chatPanel.lastAssistantMessage(page).locator('.mago-entity');
    await expectShownLiterally(row);
    await expect(row).toHaveAttribute('href', REVIEW_URL);
    expect(await wasScriptRun(page)).toBe(false);
  });

  test('it shows a neutral label for a token the conversation cannot resolve', async ({page}) => {
    await chatMock.install(page, vaultAnswer(['Written by mago://nickname_7.'], {}));
    await chatPanel.openOnDashboard(page);
    await chatPanel.askAndAwaitReply(page, 'Who wrote it?');

    await expect(chatPanel.lastAssistantMessage(page)).toContainText('Written by [earlier record].');
  });

  test('it shows customer text in a reloaded conversation as text', async ({page}) => {
    await chatMock.install(page, {
      stream: [],
      history: {conversations: [{entity_id: 4243, title: 'Reviews', updated_at: '2026-01-01 10:00:00'}]},
    });
    await page.route(/\/mago\/chat\/load/, (route) => route.fulfill({
      status: 200,
      headers: {'content-type': 'application/json'},
      body: JSON.stringify({
        entity_id: 4243,
        title: 'Reviews',
        messages: [
          {entity_id: 1, role: 'user', content: 'Reply to mago://nickname_1 about **this**', created_at: '2026-01-01 10:00:00'},
          {entity_id: 2, role: 'assistant', content: 'The review says: ' + REVIEW_TOKEN, created_at: '2026-01-01 10:00:01'},
        ],
        tokens: {[REVIEW_TOKEN]: HOSTILE_TEXT, 'mago://nickname_1': '<b>Jan</b>'},
      }),
    }));
    await chatPanel.openOnDashboard(page);
    await page.locator('#mago-history').click();
    await page.locator('.mago-history-item-title', {hasText: 'Reviews'}).click();

    const question = chatPanel.userMessages(page).last();
    await expect(question).toContainText('Reply to <b>Jan</b> about **this**');
    await expect(question.locator('b, strong')).toHaveCount(0);
    await expectShownLiterally(chatPanel.lastAssistantMessage(page));
    expect(await wasScriptRun(page)).toBe(false);
  });
});

test.describe('The admin\'s own message', () => {
  test('it shows what the admin typed exactly as typed', async ({page}) => {
    await chatMock.install(page, vaultAnswer(['Noted.'], {}));
    await chatPanel.openOnDashboard(page);
    await chatPanel.askAndAwaitReply(page, HOSTILE_TEXT);

    const question = chatPanel.userMessages(page).last();
    await expectShownLiterally(question);
    expect(await wasScriptRun(page)).toBe(false);
  });
});

test.describe('A form write confirmation', () => {
  test('it shows a field value full of markup exactly as written', async ({page}) => {
    await chatMock.install(page, stageFieldValue(HOSTILE_TEXT));
    await chatPanel.openOnDashboard(page);
    await chatPanel.ask(page, 'Rename the product');

    await expect(chatPanel.confirmButton(page)).toHaveCount(1);
    const card = chatPanel.lastAssistantMessage(page).locator('.mago-ask-text');
    await expectShownLiterally(card);
    expect(await wasScriptRun(page)).toBe(false);
  });
});

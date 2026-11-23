/*
 * Copyright © Mago Assistant
 */

import {expect, test} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import ChatMock from 'Actions/backend/ChatMock';
import SpeechRecognitionStub from 'Actions/backend/SpeechRecognitionStub';
import {lookupProduct} from 'Fixtures/scenarios';

const chatPanel = new ChatPanel();
const chatMock = new ChatMock();
const speech = new SpeechRecognitionStub();

/**
 * The chat bar at the bottom of admin pages (#361): what is typed there is sent from the panel.
 * Needs the default Display Mode (button and chat bar); the voice tests need `mago/voice/enabled` 1.
 */
test.describe('Chat bar', () => {
  test.beforeEach(async ({page}) => {
    await chatMock.install(page, lookupProduct);
  });

  test('it sends a question typed in the bar from the panel', async ({page}) => {
    await chatPanel.gotoDashboardWithChatBar(page);

    await chatPanel.chatBarInput(page).fill('Which products contain candle?');
    await chatPanel.chatBarInput(page).press('Enter');

    await expect(chatPanel.panel(page)).toHaveClass(/is-open/);
    await expect(chatPanel.userMessages(page).first()).toContainText('Which products contain candle?');
    await expect(chatPanel.lastAssistantMessage(page)).toContainText('I found 2 products matching "candle".');
    await expect(chatPanel.chatBar(page)).toBeHidden();
    await expect(chatPanel.chatBarInput(page)).toHaveValue('');
  });

  test('it keeps a draft in the panel\'s field when the bar sends', async ({page}) => {
    await chatPanel.gotoDashboardWithChatBar(page);

    await chatPanel.open(page);
    await chatPanel.input(page).fill('Which orders are still unpaid');
    await chatPanel.input(page).press('Escape');
    await chatPanel.chatBarInput(page).fill('Which products contain candle?');
    await chatPanel.chatBarInput(page).press('Enter');

    await expect(chatPanel.userMessages(page).first()).toContainText('Which products contain candle?');
    await expect(chatPanel.userMessages(page)).toHaveCount(1);
    await expect(chatPanel.input(page)).toHaveValue('Which orders are still unpaid');
  });

  test('it only opens the panel when the bar is empty', async ({page}) => {
    const streamRequests = chatMock.countRequestsTo(page, /\/mago\/chat\/stream/);
    await chatPanel.gotoDashboardWithChatBar(page);

    await chatPanel.chatBarInput(page).press('Enter');

    await expect(chatPanel.panel(page)).toHaveClass(/is-open/);
    await expect(chatPanel.input(page)).toBeFocused();
    expect(streamRequests.total()).toBe(0);
  });

  test('it comes back with the focus when the panel opened from it closes', async ({page}) => {
    await chatPanel.gotoDashboardWithChatBar(page);

    await chatPanel.chatBarInput(page).press('Enter');
    await expect(chatPanel.panel(page)).toHaveClass(/is-open/);
    await chatPanel.input(page).press('Escape');

    await expect(chatPanel.chatBar(page)).toBeVisible();
    await expect(chatPanel.chatBarInput(page)).toBeFocused();
  });

  test('it opens the slash menu above the bar and inserts the chosen command', async ({page}) => {
    await chatPanel.gotoDashboardWithChatBar(page);

    await chatPanel.chatBarInput(page).click();
    await chatPanel.chatBarInput(page).pressSequentially('/cache');

    await expect(chatPanel.chatBarSlashItems(page).first()).toBeVisible();
    await chatPanel.chatBarInput(page).press('Tab');

    await expect(chatPanel.chatBarInput(page)).toHaveValue(/^\/cache \S+ $/);
    await expect(chatPanel.chatBarSlashItems(page)).toHaveCount(0);
    await expect(chatPanel.panel(page)).not.toHaveClass(/is-open/);
  });
});

test.describe('Chat bar voice input', () => {
  test.beforeEach(async ({page}) => {
    await chatMock.install(page, lookupProduct);
    await speech.install(page);
  });

  test('it asks for consent in the panel before the microphone turns on', async ({page}) => {
    await chatPanel.gotoDashboardWithChatBar(page);

    await chatPanel.chatBarMicrophone(page).click();

    await expect(chatPanel.panel(page)).toHaveClass(/is-open/);
    await expect(page.locator('.mago-voice-consent')).toBeVisible();
    expect((await speech.log(page)).started).toBe(0);
  });

  test('it keeps the focus in the panel when the notice is declined', async ({page}) => {
    await chatPanel.gotoDashboardWithChatBar(page);

    await chatPanel.chatBarMicrophone(page).click();
    await page.locator('.mago-voice-consent').getByRole('button', {name: 'Not now'}).click();

    await expect(chatPanel.input(page)).toBeFocused();
    expect((await speech.log(page)).started).toBe(0);
  });

  test('it puts the spoken words in the bar without opening the panel', async ({page}) => {
    const streamRequests = chatMock.countRequestsTo(page, /\/mago\/chat\/stream/);
    await speech.rememberConsent(page);
    await chatPanel.gotoDashboardWithChatBar(page);

    await chatPanel.chatBarMicrophone(page).click();
    await expect(chatPanel.chatBarMicrophone(page)).toHaveAttribute('aria-pressed', 'true');
    await speech.hear(page, 'Which products contain candle');
    await speech.pause(page);

    await expect(chatPanel.chatBarInput(page)).toHaveValue('Which products contain candle');
    await expect(chatPanel.chatBarInput(page)).toBeFocused();
    await expect(chatPanel.chatBarMicrophone(page)).toHaveAttribute('aria-pressed', 'false');
    await expect(chatPanel.panel(page)).not.toHaveClass(/is-open/);
    expect(streamRequests.total()).toBe(0);
  });

  test('it stops recording when the bar sends its text', async ({page}) => {
    await speech.rememberConsent(page);
    await chatPanel.gotoDashboardWithChatBar(page);

    await chatPanel.chatBarMicrophone(page).click();
    await speech.hear(page, 'Which products contain candle');
    await chatPanel.chatBarInput(page).press('Enter');

    await expect(chatPanel.userMessages(page).first()).toContainText('Which products contain candle');
    expect((await speech.log(page)).aborted).toBe(1);
    await expect(chatPanel.chatBarInput(page)).toHaveValue('');
  });
});

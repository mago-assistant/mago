/*
 * Copyright © Mago Assistant
 */

import {expect, type Page, test} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import ChatMock from 'Actions/backend/ChatMock';
import SpeechRecognitionStub from 'Actions/backend/SpeechRecognitionStub';
import {lookupProduct} from 'Fixtures/scenarios';

const chatPanel = new ChatPanel();
const chatMock = new ChatMock();
const speech = new SpeechRecognitionStub();

/**
 * Voice input (#141) is dictation only: the microphone puts the spoken words into the input and
 * the administrator sends them like typed text. Requires `mago/voice/enabled` set to 1.
 */
const microphone = (page: Page) => page.locator('#mago-mic');
const consentCard = (page: Page) => page.locator('.mago-voice-consent');

async function openWithMicrophone(page: Page) {
  await chatMock.install(page, lookupProduct);
  await speech.install(page);
  await chatPanel.openOnDashboard(page);
}

async function openWithConsentGiven(page: Page) {
  await speech.rememberConsent(page);
  await openWithMicrophone(page);
}

test.describe('Voice input', () => {
  test('it hides the microphone in a browser without speech recognition', async ({page}) => {
    await chatMock.install(page, lookupProduct);
    await speech.removeFromBrowser(page);

    await chatPanel.openOnDashboard(page);

    await expect(microphone(page)).toBeHidden();
  });

  test('it asks for consent before the microphone turns on the first time', async ({page}) => {
    await openWithMicrophone(page);

    await microphone(page).click();

    await expect(consentCard(page)).toBeVisible();
    expect((await speech.log(page)).started).toBe(0);
  });

  test('it starts recording once the administrator accepts the notice', async ({page}) => {
    await openWithMicrophone(page);

    await microphone(page).click();
    await consentCard(page).getByRole('button', {name: 'I understand, turn on the microphone'}).click();

    await expect(consentCard(page)).toHaveCount(0);
    await expect(microphone(page)).toHaveAttribute('aria-pressed', 'true');
    expect((await speech.log(page)).started).toBe(1);
  });

  test('it does not ask again once the notice was accepted in this browser', async ({page}) => {
    await openWithConsentGiven(page);

    await microphone(page).click();

    await expect(consentCard(page)).toHaveCount(0);
    expect((await speech.log(page)).started).toBe(1);
  });

  test('it keeps the microphone off when the administrator declines the notice', async ({page}) => {
    await openWithMicrophone(page);

    await microphone(page).click();
    await consentCard(page).getByRole('button', {name: 'Not now'}).click();

    await expect(consentCard(page)).toHaveCount(0);
    await expect(microphone(page)).toHaveAttribute('aria-pressed', 'false');
    expect((await speech.log(page)).started).toBe(0);
  });

  test('it puts the spoken words in the input without sending them', async ({page}) => {
    const streamRequests = chatMock.countRequestsTo(page, /\/mago\/chat\/stream/);
    await openWithConsentGiven(page);

    await microphone(page).click();
    await speech.hear(page, 'Which products', ' contain candle');
    await speech.pause(page);

    await expect(chatPanel.input(page)).toHaveValue('Which products contain candle');
    await expect(chatPanel.userMessages(page)).toHaveCount(0);
    expect(streamRequests.total()).toBe(0);
  });

  test('it adds the spoken words after text already in the input', async ({page}) => {
    await openWithConsentGiven(page);
    await chatPanel.input(page).fill('Show me');

    await microphone(page).click();
    await speech.hear(page, 'the newest orders');
    await speech.pause(page);

    await expect(chatPanel.input(page)).toHaveValue('Show me the newest orders');
  });

  test('it leaves the cursor in the input when recording stops', async ({page}) => {
    await openWithConsentGiven(page);

    await microphone(page).click();
    await speech.hear(page, 'Which products contain candle');
    await speech.pause(page);

    await expect(chatPanel.input(page)).toBeFocused();
    await expect(microphone(page)).toHaveAttribute('aria-pressed', 'false');
  });

  test('it stops recording when the microphone is pressed again', async ({page}) => {
    await openWithConsentGiven(page);

    await microphone(page).click();
    await speech.hear(page, 'Which products');
    await microphone(page).click();

    await expect(microphone(page)).toHaveAttribute('aria-pressed', 'false');
    await expect(chatPanel.input(page)).toHaveValue('Which products');
    expect((await speech.log(page)).stopped).toBe(1);
  });

  test('it sends the dictated text only when the administrator presses Enter', async ({page}) => {
    await openWithConsentGiven(page);

    await microphone(page).click();
    await speech.hear(page, 'Which products contain candle');
    await speech.pause(page);
    await chatPanel.input(page).press('Enter');

    await expect(chatPanel.userMessages(page)).toHaveCount(1);
    await expect(chatPanel.userMessages(page).first()).toContainText('Which products contain candle');
  });

  test('it stops listening when the administrator starts typing', async ({page}) => {
    await openWithConsentGiven(page);

    await microphone(page).click();
    await speech.hear(page, 'Which products');
    await chatPanel.input(page).press('End');

    await expect(microphone(page)).toHaveAttribute('aria-pressed', 'false');
    expect((await speech.log(page)).aborted).toBe(1);
  });

  test('it listens in the administrator\'s interface language', async ({page}) => {
    await openWithConsentGiven(page);

    await microphone(page).click();

    expect((await speech.log(page)).lang).toMatch(/^[a-z]{2,3}(-[A-Za-z0-9]+)+$/);
  });

  test('it explains when the browser blocks the microphone', async ({page}) => {
    await openWithConsentGiven(page);

    await microphone(page).click();
    await speech.fail(page, 'not-allowed');

    await expect(chatPanel.assistantMessages(page).last()).toContainText('Microphone access was denied');
    await expect(microphone(page)).toHaveAttribute('aria-pressed', 'false');
  });
});

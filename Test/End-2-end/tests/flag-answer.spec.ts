/*
 * Copyright © Mago Assistant
 */

import {expect, test} from '@playwright/test';
import {readFile} from 'node:fs/promises';
import ChatPanel from 'Pages/backend/ChatPanel';
import FlaggedAnswers from 'Pages/backend/FlaggedAnswers';
import {PROVIDER_ROUND_TRIP_TIMEOUT} from 'Config/timeouts';

const chatPanel = new ChatPanel();
const flaggedAnswers = new FlaggedAnswers();

const QUESTION = 'Please run the E2E Flag Answer Check.';
const ANSWER = 'Fourteen orders are on hold.';

/**
 * Rating goes through the real backend: the answer has to be stored for there to be a message id
 * to rate, and the feedback has to be read back from the database on the Answer Feedback screen.
 * Only the provider is mocked, by WireMock (wiremock/mappings/flag-answer.json).
 */
test.describe('Rate an answer', () => {
  /* The Answer Feedback grid keeps its keyword search per admin, and every spec shares one admin,
     so a search in one test would empty the grid another test borrows a view link from. */
  test.describe.configure({mode: 'serial'});

  test('Keeps a thumbs down with its note and lets an admin resolve, download and delete it', async ({page}) => {
    const note = 'It said fourteen, there were nine ' + Date.now();

    await chatPanel.openOnDashboard(page);
    await chatPanel.ask(page, QUESTION);
    await expect(chatPanel.lastAssistantMessage(page)).toContainText(ANSWER, {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    const flagId = await chatPanel.rateLastAnswerWithNote(page, 'down', note);

    await expect(chatPanel.feedbackLine(page)).toContainText('Thanks for your feedback.');
    await expect(chatPanel.rateButton(page, 'down')).toHaveAttribute('aria-pressed', 'true');
    await expect(chatPanel.rateButton(page, 'up')).toHaveAttribute('aria-pressed', 'false');

    await flaggedAnswers.openFlag(page, flagId);

    await expect(flaggedAnswers.content(page)).toContainText(ANSWER);
    await expect(flaggedAnswers.content(page)).toContainText(QUESTION);
    await expect(flaggedAnswers.content(page)).toContainText(note);
    await expect(flaggedAnswers.status(page)).toHaveText(/open/i);
    await expect(flaggedAnswers.rating(page)).toHaveText(/thumbs down/i);

    await flaggedAnswers.resolve(page);

    await expect(flaggedAnswers.successMessage(page, 'Feedback marked as resolved.')).toBeVisible();
    await expect(flaggedAnswers.status(page)).toHaveText(/resolved/i);

    await flaggedAnswers.reopen(page);

    await expect(flaggedAnswers.successMessage(page, 'Feedback reopened.')).toBeVisible();
    await expect(flaggedAnswers.status(page)).toHaveText(/open/i);

    const download = await flaggedAnswers.download(page);
    const bundle = JSON.parse(await readFile(await download.path(), 'utf8'));

    expect(download.suggestedFilename()).toBe('mago-flag-' + flagId + '.json');
    expect(bundle.flag.note).toBe(note);
    expect(bundle.flag.rating).toBe('down');
    expect(bundle.snapshot.answer.content).toBe(ANSWER);
    expect(bundle.snapshot.usage.calls.length, 'the provider call of the turn is part of the flag').toBeGreaterThan(0);

    const flagUrl = page.url();

    await flaggedAnswers.delete(page);

    await expect(flaggedAnswers.successMessage(page, 'The feedback was deleted.')).toBeVisible();

    await page.goto(flagUrl, {waitUntil: 'load'});

    await expect(flaggedAnswers.errorMessage(page, 'This feedback no longer exists.')).toBeVisible();
  });

  test('Deletes every flag the grid shows with Select All and the mass action', async ({page}) => {
    const keyword = 'e2emassdelete' + Date.now();

    await chatPanel.openOnDashboard(page);
    await chatPanel.ask(page, QUESTION);
    await expect(chatPanel.lastAssistantMessage(page)).toContainText(ANSWER, {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await chatPanel.rateLastAnswerWithNote(page, 'down', 'Mass delete ' + keyword);
    await flaggedAnswers.openGrid(page);
    await flaggedAnswers.search(page, keyword);
    await expect(flaggedAnswers.gridRows(page)).toHaveCount(1);

    await flaggedAnswers.deleteAllMatching(page);

    await expect(flaggedAnswers.successMessage(page, '1 feedback item(s) were deleted.')).toBeVisible();
    await flaggedAnswers.search(page, keyword);
    await expect(flaggedAnswers.gridRows(page)).toHaveCount(0);
    await flaggedAnswers.clearSearch(page);
  });

  test('Keeps a thumbs up without a note, and lets the admin switch it and take it back', async ({page}) => {
    await chatPanel.openOnDashboard(page);
    await chatPanel.ask(page, QUESTION);
    await expect(chatPanel.lastAssistantMessage(page)).toContainText(ANSWER, {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    const flagId = await chatPanel.rateLastAnswer(page, 'up');

    await expect(chatPanel.rateButton(page, 'up')).toHaveAttribute('aria-pressed', 'true');
    await expect(chatPanel.feedbackCard(page)).toContainText('(optional)');

    await chatPanel.feedbackCard(page).locator('input').press('Escape');

    await expect(chatPanel.feedbackCard(page)).toHaveCount(0);
    await expect(chatPanel.rateButton(page, 'up')).toHaveAttribute('aria-pressed', 'true');

    const switchedId = await chatPanel.rateLastAnswer(page, 'down');

    expect(switchedId, 'switching thumbs changes the feedback, it does not add a second one').toBe(flagId);
    await expect(chatPanel.rateButton(page, 'down')).toHaveAttribute('aria-pressed', 'true');
    await expect(chatPanel.rateButton(page, 'up')).toHaveAttribute('aria-pressed', 'false');

    await chatPanel.rateButton(page, 'down').click();

    await expect(chatPanel.feedbackLine(page)).toContainText('Feedback removed.');
    await expect(chatPanel.rateButton(page, 'down')).toHaveAttribute('aria-pressed', 'false');
  });
});

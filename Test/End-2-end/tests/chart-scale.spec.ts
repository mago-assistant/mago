/*
 * Copyright © Mago Assistant
 */

import {expect, test} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import ChatMock from 'Actions/backend/ChatMock';
import {widgetAnswer} from 'Fixtures/scenarios';

const chatPanel = new ChatPanel();
const chatMock = new ChatMock();

const revenuePerDay = {
  type: 'columns',
  label: 'Revenue per day (EUR)',
  points: [
    {label: '1', value: 620},
    {label: '2', value: 1480},
    {label: '3', value: 940},
  ],
};

test('Shows the scale of a column chart on its y-axis', async ({page}) => {
  await chatMock.install(page, widgetAnswer(revenuePerDay));
  await chatPanel.openOnDashboard(page);

  await chatPanel.askAndAwaitReply(page, 'Revenue per day this month');

  const ticks = chatPanel.lastAssistantMessage(page).locator('.mago-columns .mago-y-tick');
  await expect(ticks).toHaveText(['1,480', '740', '0']);
});

test('Shows the value of a column in a tooltip on hover', async ({page}) => {
  await chatMock.install(page, widgetAnswer(revenuePerDay));
  await chatPanel.openOnDashboard(page);

  await chatPanel.askAndAwaitReply(page, 'Revenue per day this month');
  const column = chatPanel.lastAssistantMessage(page).locator('.mago-column').nth(1);
  const tooltip = column.locator('.mago-tip');
  await expect(tooltip).toBeHidden();
  await column.hover();

  await expect(tooltip).toBeVisible();
  await expect(tooltip).toHaveText('2 · 1,480');
  await expect(column).toHaveAttribute('aria-label', '2 · 1,480');
  await expect(column).not.toHaveAttribute('title');
});

test('Shows the value of a column in a tooltip on keyboard focus', async ({page}) => {
  await chatMock.install(page, widgetAnswer(revenuePerDay));
  await chatPanel.openOnDashboard(page);

  await chatPanel.askAndAwaitReply(page, 'Revenue per day this month');
  const columns = chatPanel.lastAssistantMessage(page).locator('.mago-column');
  await columns.nth(0).focus();
  await page.keyboard.press('Tab');

  await expect(columns.nth(1)).toBeFocused();
  await expect(columns.nth(1).locator('.mago-tip')).toBeVisible();
});

test('Shows every series of a line chart at the hovered position', async ({page}) => {
  await chatMock.install(page, widgetAnswer({
    type: 'lines',
    label: 'Orders per week',
    series: [
      {label: 'now', points: [80, 88, 94]},
      {label: 'previous', points: [70, 76, 86], style: 'dashed'},
    ],
    xLabels: ['w1', 'w2', 'w3'],
  }));
  await chatPanel.openOnDashboard(page);

  await chatPanel.askAndAwaitReply(page, 'Orders per week compared to last period');
  const widget = chatPanel.lastAssistantMessage(page).locator('.mago-lines');
  const lastPoint = widget.locator('.mago-line-point').last();
  await lastPoint.hover();

  await expect(lastPoint.locator('.mago-tip')).toBeVisible();
  await expect(lastPoint.locator('.mago-tip')).toHaveText('w3 · now 94 · previous 86');
  await expect(widget.locator('.mago-y-tick')).toHaveText(['94', '82', '70']);
  await expect(widget.locator('.mago-line-point').first()).toHaveAttribute('style', /--mago-guide: *[0-9.]+%/);
});

test('Shows the series value of a stacked column segment on hover', async ({page}) => {
  await chatMock.install(page, widgetAnswer({
    type: 'stackedColumns',
    label: 'Orders per channel',
    series: [{label: 'web'}, {label: 'app'}],
    points: [
      {label: 'Mon', values: [41, 19]},
      {label: 'Tue', values: [30, 10]},
    ],
  }));
  await chatPanel.openOnDashboard(page);

  await chatPanel.askAndAwaitReply(page, 'Orders per channel this week');
  const widget = chatPanel.lastAssistantMessage(page).locator('.mago-stacked');
  const webOnMonday = widget.locator('.mago-stack').first().locator('.mago-stack-seg').last();
  await webOnMonday.hover();

  await expect(webOnMonday.locator('.mago-tip')).toBeVisible();
  await expect(webOnMonday.locator('.mago-tip')).toHaveText('Mon · web 41');
  await expect(widget.locator('.mago-y-tick')).toHaveText(['60', '30', '0']);
});

test('Shows the series value nearest to the hovered position when series differ in length', async ({page}) => {
  await chatMock.install(page, widgetAnswer({
    type: 'lines',
    label: 'Revenue this month so far',
    series: [
      {label: 'now', points: [10, 20, 30]},
      {label: 'previous', points: [5, 6, 7, 8, 9]},
    ],
  }));
  await chatPanel.openOnDashboard(page);

  await chatPanel.askAndAwaitReply(page, 'Revenue this month compared to last month');
  const middlePoint = chatPanel.lastAssistantMessage(page).locator('.mago-line-point').nth(2);
  await middlePoint.hover();

  await expect(middlePoint.locator('.mago-tip')).toHaveText('now 20 · previous 7');
});

test('Labels ticks with enough decimals to tell a narrow range apart', async ({page}) => {
  await chatMock.install(page, widgetAnswer([
    {type: 'lines', label: 'Average order value', series: [{label: 'now', points: [120.5, 121.0, 121.4]}]},
    {type: 'columns', label: 'Return rate', points: [{label: 'Q1', value: 0.02}, {label: 'Q2', value: 0.04}]},
  ]));
  await chatPanel.openOnDashboard(page);

  await chatPanel.askAndAwaitReply(page, 'Average order value and return rate');
  const message = chatPanel.lastAssistantMessage(page);

  await expect(message.locator('.mago-lines .mago-y-tick')).toHaveText(['121.4', '120.95', '120.5']);
  await expect(message.locator('.mago-columns .mago-y-tick')).toHaveText(['0.04', '0.02', '0']);
});

test('Shortens large ticks that span the scale and keeps the full value in the tooltip', async ({page}) => {
  await chatMock.install(page, widgetAnswer({
    type: 'columns',
    label: 'Revenue per quarter',
    points: [{label: 'Q1', value: 1250000}, {label: 'Q2', value: 2480000}],
  }));
  await chatPanel.openOnDashboard(page);

  await chatPanel.askAndAwaitReply(page, 'Revenue per quarter');
  const widget = chatPanel.lastAssistantMessage(page).locator('.mago-columns');
  const firstQuarter = widget.locator('.mago-column').first();
  await firstQuarter.hover();

  await expect(widget.locator('.mago-y-tick')).toHaveText(['2.5M', '1.2M', '0']);
  await expect(firstQuarter.locator('.mago-tip')).toHaveText('Q1 · 1,250,000');
});

test('Shows the value of a composition segment and a heatmap cell on hover', async ({page}) => {
  await chatMock.install(page, widgetAnswer([
    {type: 'composition', label: 'Orders per channel', segments: [
      {label: 'web', value: 60},
      {label: 'app', value: 30, valueText: '30 orders'},
    ]},
    {type: 'heatmap', label: 'Orders per hour', rows: ['mo', 'tu'], values: [[1, 4], [2, 8]]},
  ]));
  await chatPanel.openOnDashboard(page);

  await chatPanel.askAndAwaitReply(page, 'When do orders come in?');
  const message = chatPanel.lastAssistantMessage(page);
  const appSegment = message.locator('.mago-composition-seg').nth(1);
  await appSegment.hover();
  await expect(appSegment.locator('.mago-tip')).toHaveText('app · 30 orders');
  const busiestCell = message.locator('.mago-heat-cell').last();
  await busiestCell.hover();

  await expect(busiestCell.locator('.mago-tip')).toBeVisible();
  await expect(busiestCell.locator('.mago-tip')).toHaveText('tu · 8');
  await expect(busiestCell).not.toHaveAttribute('title');
});

test('Formats ticks and tooltips as money when the chart has a currency', async ({page}) => {
  await chatMock.install(page, widgetAnswer({...revenuePerDay, currency: 'EUR'}));
  await chatPanel.openOnDashboard(page);

  await chatPanel.askAndAwaitReply(page, 'Revenue per day this month');
  const widget = chatPanel.lastAssistantMessage(page).locator('.mago-columns');
  const column = widget.locator('.mago-column').nth(1);
  await column.hover();

  await expect(widget.locator('.mago-y-tick')).toHaveText(['€1,480', '€740', '€0']);
  await expect(column.locator('.mago-tip')).toHaveText('2 · €1,480.00');
});

test('Ignores a currency that is not an ISO code', async ({page}) => {
  await chatMock.install(page, widgetAnswer({...revenuePerDay, currency: 'euro'}));
  await chatPanel.openOnDashboard(page);

  await chatPanel.askAndAwaitReply(page, 'Revenue per day this month');

  await expect(chatPanel.lastAssistantMessage(page).locator('.mago-columns .mago-y-tick')).toHaveText(['1,480', '740', '0']);
});

test('Keeps fourteen labelled columns inside the widget and labels every other day around the peak', async ({page}) => {
  const revenue = [212, 180, 391, 205, 601, 1038, 160, 95, 70, 165, 280, 560, 190, 120];
  await chatMock.install(page, widgetAnswer({
    type: 'columns',
    label: 'Revenue per day, Oct 2–15',
    currency: 'EUR',
    points: revenue.map((value, i) => ({label: `Oct ${i + 2}`, value})),
  }));
  await chatPanel.openOnDashboard(page);

  await chatPanel.askAndAwaitReply(page, 'Revenue per day in the first half of October');
  const widget = chatPanel.lastAssistantMessage(page).locator('.mago-columns');
  const widgetBox = await widget.boundingBox();
  const plotBox = await widget.locator('.mago-columns-plot').boundingBox();

  expect(plotBox!.x + plotBox!.width).toBeLessThanOrEqual(widgetBox!.x + widgetBox!.width);
  await expect(widget.locator('.mago-axis-label.is-peak')).toHaveText('Oct 7');
  await expect(widget.locator('.mago-axis-label')).toHaveText([
    '', 'Oct 3', '', 'Oct 5', '', 'Oct 7', '', 'Oct 9', '', 'Oct 11', '', 'Oct 13', '', 'Oct 15',
  ]);
  await expect(widget.locator('.mago-column').nth(4)).toHaveAttribute('aria-label', 'Oct 6 · €601.00');
});

/*
 * Copyright © Mago Assistant
 */

import {expect, test} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';
import MagentoApi from 'Services/MagentoApi';
import {PROVIDER_ROUND_TRIP_TIMEOUT} from 'Config/timeouts';

const chatPanel = new ChatPanel();
const magentoApi = new MagentoApi();

const TOOL = 'fixture_blocks';
const IDENTIFIER = 'e2e-manifest-block';

/**
 * A tool that exists only as etc/mago_tools.xml in another module (MagoFixture_Manifest, installed
 * by the workflow from Test/End-2-end/fixtures). Nothing is mocked in the browser: the manifest is
 * read, the tool is offered to the provider, the write waits for the admin and then runs through
 * Magento's own cmsBlock route. Only the provider is replayed by WireMock.
 */
test.describe('Manifest tool', () => {
  test.describe.configure({mode: 'serial'});

  test.beforeEach(async ({request}) => {
    await magentoApi.deleteCmsBlock(request, IDENTIFIER);
  });

  test.afterEach(async ({request}) => {
    await magentoApi.deleteCmsBlock(request, IDENTIFIER);
  });

  test('it is listed on the skills grid under its module', async ({page}) => {
    const items = await chatPanel.openSkillsGrid(page);
    const tool = items.find((item: any) => item.name === TOOL);

    expect(tool, 'the manifest tool is not in the registry').toBeDefined();
    expect(tool.category).toBe('MagoFixture_Manifest');
    expect(tool.description).toContain('Find and create CMS blocks through a manifest');
  });

  test('it writes through the module route only after the admin confirms', async ({page, request}) => {
    await chatPanel.openOnDashboard(page);
    await chatPanel.ask(page, 'Please run the E2E manifest check and create the CMS block for it.');

    await expect(chatPanel.toolTags(page)).toHaveText([new RegExp(TOOL)], {timeout: PROVIDER_ROUND_TRIP_TIMEOUT});
    await expect(chatPanel.confirmButton(page)).toBeVisible({timeout: PROVIDER_ROUND_TRIP_TIMEOUT});

    expect(
      await magentoApi.findCmsBlock(request, IDENTIFIER),
      'the block must not exist before the admin confirms'
    ).toBeNull();

    await chatPanel.confirmButton(page).click();

    await expect(chatPanel.lastAssistantMessage(page)).toContainText(
      IDENTIFIER + ' was created',
      {timeout: PROVIDER_ROUND_TRIP_TIMEOUT}
    );

    const created = await magentoApi.findCmsBlock(request, IDENTIFIER);

    expect(created, 'the block was not created').not.toBeNull();
    expect(created.title).toBe('E2E Manifest Block');
    expect(created.content).toContain('Created through a manifest tool.');
    expect(created.active, 'the manifest default for active is false').toBe(false);
  });
});

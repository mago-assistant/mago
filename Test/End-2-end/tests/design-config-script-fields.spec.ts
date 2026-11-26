/*
 * Copyright © Mago Assistant
 */

import {expect, test, type Page} from '@playwright/test';
import ChatPanel from 'Pages/backend/ChatPanel';

const chatPanel = new ChatPanel();

const DESIGN_CONFIG_EDIT = 'theme/design_config/edit';
const HEAD_SCRIPTS_PATH = 'data.head_includes';
const FOOTER_HTML_PATH = 'data.footer_absolute_footer';
const MARKER = 'AUDIT_MARKER';

async function snapshot(page: Page) {
  return page.evaluate(() => (window as any).magoFormBridge.snapshot());
}

async function stage(page: Page, path: string) {
  return page.evaluate(({fieldPath, value}) => new Promise<any>((resolve) => {
    const bridge = (window as any).magoFormBridge;
    const form = bridge.snapshot();

    bridge.apply({
      type: 'form_write',
      target: {namespace: form.namespace, entity_id: form.entityId, store_id: form.storeId || '', is_new: true},
      changes: [{path: fieldPath, label: fieldPath, value}],
    }, (result: any) => resolve({applied: result.applied, failed: result.failed, refused: !!result.refused}));
  }), {fieldPath: path, value: MARKER});
}

async function stagedValue(page: Page, path: string) {
  return (await snapshot(page)).fields.find((field: any) => field.path === path)?.value;
}

/**
 * Security audit follow-up 2026-11-02, finding 3. config_writer never changes
 * design/head/includes and design/footer/absolute_footer (BlockedConfigPaths), the scripts every
 * storefront page loads. Content > Design > Configuration shows the same two settings as fields
 * of design_config_form, so the bridge must not stage a value into them either. Nothing here
 * clicks Save: the marker only ever lives in the open form and is gone on the next page load.
 */
test.describe('Design Configuration script fields', () => {
  test('it does not stage a value into the head scripts field', async ({page}) => {
    test.setTimeout(60000);

    await chatPanel.openOn(page, DESIGN_CONFIG_EDIT);
    const result = await stage(page, HEAD_SCRIPTS_PATH);

    expect(result.applied).toEqual([]);
    expect(await stagedValue(page, HEAD_SCRIPTS_PATH)).not.toBe(MARKER);
  });

  test('it does not stage a value into the footer miscellaneous HTML field', async ({page}) => {
    test.setTimeout(60000);

    await chatPanel.openOn(page, DESIGN_CONFIG_EDIT);
    const result = await stage(page, FOOTER_HTML_PATH);

    expect(result.applied).toEqual([]);
    expect(await stagedValue(page, FOOTER_HTML_PATH)).not.toBe(MARKER);
  });

  test('it still stages a value into the default page title field', async ({page}) => {
    test.setTimeout(60000);

    await chatPanel.openOn(page, DESIGN_CONFIG_EDIT);
    const result = await stage(page, 'data.head_default_title');

    expect(result.applied.map((change: any) => change.path)).toEqual(['data.head_default_title']);
  });
});

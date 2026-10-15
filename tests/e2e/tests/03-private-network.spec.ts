import { test, expect } from '@playwright/test';
import { DEFAULT_GLOBALS, entryText, feedWithItem, openEntry, saveGlobals } from './helpers';


test.afterAll(async ({ browser }) => {
  const page = await browser.newPage({ storageState: '.auth/admin.json' });
  await saveGlobals(page, DEFAULT_GLOBALS);
  await page.close();
});

test('articles on private networks are not fetched unless explicitly allowed', async ({ page, request }) => {
  await saveGlobals(page, { allow_private_network: false });
  const blocked = await feedWithItem(page, request, 'standard');
  const blockedText = entryText(await openEntry(page, blocked.feedId, blocked.itemId));
  await expect(blockedText).toContainText(`RSS_SUMMARY_${blocked.itemId}`);
  await expect(blockedText).not.toContainText('FULLTEXT_PARAGRAPH_ONE');

  await saveGlobals(page, { allow_private_network: true });
  const allowed = await feedWithItem(page, request, 'standard');
  await expect(entryText(await openEntry(page, allowed.feedId, allowed.itemId)))
    .toContainText('FULLTEXT_PARAGRAPH_ONE');
});

import { test, expect, type Page } from '@playwright/test';
import {
  CONFIGURE_URL, DEFAULT_GLOBALS, OBSCURA_VERSION, entryText, feedWithItem, openEntry, saveGlobals,
  statusCell, submitConfigure,
} from './helpers';

async function redownload(page: Page): Promise<void> {
  await submitConfigure(page, page.getByRole('button', { name: 'Redownload obscura binary' }));
}

test.afterEach(async ({ page }) => {
  await saveGlobals(page, DEFAULT_GLOBALS);
  await redownload(page);
  await page.goto(CONFIGURE_URL);
  await expect(statusCell(page, 'Obscura build')).toHaveText('standard');
});

test('enabling the stealth build downloads it', async ({ page }) => {
  await saveGlobals(page, { obscura_stealth_build: true });
  await redownload(page);
  await page.goto(CONFIGURE_URL);
  await expect(page.locator('#obscura_stealth_build')).toBeChecked();
  await expect(statusCell(page, 'Obscura build')).toHaveText('stealth');
  await expect(statusCell(page, 'Obscura version')).toContainText(OBSCURA_VERSION);
  await expect(page.locator('.ftc-build-warning')).toHaveCount(0);
});

test('a changed build setting is flagged and applied on the next fetch', async ({ page, request }) => {
  await saveGlobals(page, DEFAULT_GLOBALS);
  await redownload(page);
  await saveGlobals(page, { obscura_stealth_build: true });
  await page.goto(CONFIGURE_URL);
  await expect(statusCell(page, 'Obscura build')).toHaveText('standard');
  await expect(page.locator('.ftc-build-warning')).toContainText('stealth');

  // The next fetch replaces the binary with the stealth build and extracts with it.
  const { feedId, itemId } = await feedWithItem(page, request, 'js-rendered', { stealth: true });
  await expect(entryText(await openEntry(page, feedId, itemId))).toContainText('JS_RENDERED_PARAGRAPH');
  await page.goto(CONFIGURE_URL);
  await expect(statusCell(page, 'Obscura build')).toHaveText('stealth');
  await expect(page.locator('.ftc-build-warning')).toHaveCount(0);
});

test('per-feed stealth with the standard build is flagged', async ({ page, request }) => {
  await saveGlobals(page, DEFAULT_GLOBALS);
  await redownload(page);
  await feedWithItem(page, request, 'standard', { stealth: true });
  await page.goto(CONFIGURE_URL);
  await expect(page.locator('.ftc-stealth-warning')).toBeVisible();

  await saveGlobals(page, { obscura_stealth_build: true });
  await redownload(page);
  await page.goto(CONFIGURE_URL);
  await expect(page.locator('.ftc-stealth-warning')).toHaveCount(0);
});

test('a custom download URL without {variant} cannot select the build', async ({ page }) => {
  await saveGlobals(page, {
    obscura_stealth_build: true,
    obscura_download_url: 'http://fixtures:8080/obscura/obscura-{arch}.tar.gz',
  });
  await page.goto(CONFIGURE_URL);
  await expect(page.locator('.ftc-build-warning')).toContainText('{variant}');
});

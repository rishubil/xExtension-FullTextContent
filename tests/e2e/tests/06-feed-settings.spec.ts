import { test, expect, type Locator, type Page } from '@playwright/test';
import {
  CONFIGURE_URL, DEFAULT_GLOBALS, enableInForm, feedForm, feedItem, gotoConfigure, openFeedOptions, publish, refreshFeed,
  saveGlobals, setFeedOptions, submitConfigure, subscribe, uid,
} from './helpers';


test.beforeAll(async ({ browser }) => {
  const page = await browser.newPage({ storageState: '.auth/admin.json' });
  await saveGlobals(page, DEFAULT_GLOBALS);
  await page.close();
});

function filterBox(page: Page): Locator {
  return page.getByRole('searchbox', { name: 'Filter feeds' });
}

test('every per-feed option has a descriptive label and an explanation', async ({ page }) => {
  const feedId = await subscribe(page, uid('help'));
  await gotoConfigure(page);
  await enableInForm(page, feedId);
  const item = feedItem(page, feedId);
  await openFeedOptions(item);
  for (const label of ['Wait for element (CSS selector)', 'Extra delay (seconds)', 'Page is ready at',
    'Page load timeout (seconds)', 'Stealth mode']) {
    const field = item.getByLabel(label, { exact: true });
    await expect(field).toBeVisible();
    // Each option is described next to it (aria-describedby → help text).
    await expect(field).toHaveAttribute('aria-describedby', /.+/);
    const helpId = await field.getAttribute('aria-describedby');
    await expect(page.locator(`[id="${helpId}"]`)).not.toBeEmpty();
  }
  // The ready-state choices say what they mean, not just the raw event names.
  await expect(item.getByLabel('Page is ready at', { exact: true }).locator('option')).toContainText([
    /default/i, /HTML/i, /network/i,
  ]);
});

test('enabled feeds are listed first and the others are under "Add feeds"', async ({ page }) => {
  const on = await subscribe(page, uid('on'));
  const off = await subscribe(page, uid('off'));
  await setFeedOptions(page, on, { enabled: true });
  await setFeedOptions(page, off, { enabled: false });

  await gotoConfigure(page);
  await expect(page.locator('.ftc-enabled-list').locator(`#feed_${on}`)).toBeVisible();
  await expect(page.locator('.ftc-enabled-list').locator(`#feed_${off}`)).toHaveCount(0);
  await expect(page.locator('.ftc-add-list').locator(`#feed_${off}`)).toBeHidden();
  const enabled = await page.locator('.ftc-enabled-list .ftc-feed').count();
  await expect(page.locator('.ftc-enabled-count')).toContainText(String(enabled));

  await page.locator('.ftc-add-panel > summary').click();
  await expect(page.locator('.ftc-add-list').locator(`#feed_${off}`)).toBeVisible();
  // Feeds that are not enabled have no fetch options to configure yet.
  await expect(feedItem(page, off).locator('.ftc-feed-options')).toBeHidden();
});

test('feeds can be found by name in "Add feeds"', async ({ page }) => {
  const token = uid('flt');
  const alpha = await subscribe(page, `${token}-alpha`);
  const beta = await subscribe(page, `${token}-beta`);
  await gotoConfigure(page);
  await page.locator('.ftc-add-panel > summary').click();
  await filterBox(page).fill(`${token}-alpha`);
  await expect(feedItem(page, alpha)).toBeVisible();
  await expect(feedItem(page, beta)).toBeHidden();
  await filterBox(page).fill('no-feed-matches-this');
  await expect(page.locator('.ftc-no-match')).toBeVisible();
});

test('enabling a feed moves it to the enabled feeds, where it can be configured before saving', async ({ page }) => {
  const feedId = await subscribe(page, uid('add'));
  await gotoConfigure(page);
  const before = await page.locator('.ftc-enabled-list .ftc-feed').count();
  await expect(page.locator('.ftc-dirty-note')).toBeHidden();

  await enableInForm(page, feedId);
  await expect(page.locator('.ftc-enabled-list').locator(`#feed_${feedId}`)).toBeChecked();
  await expect(page.locator('.ftc-enabled-count')).toContainText(String(before + 1));
  await expect(page.locator('.ftc-dirty-note')).toBeVisible();
  await openFeedOptions(feedItem(page, feedId));
  await feedItem(page, feedId).getByLabel('Extra delay (seconds)').fill('4');

  await submitConfigure(page, feedForm(page).getByRole('button', { name: 'Save feed settings' }));
  await gotoConfigure(page);
  await expect(page.locator('.ftc-enabled-list').locator(`#feed_${feedId}`)).toBeChecked();
  await expect(feedItem(page, feedId).locator('.ftc-feed-summary')).toContainText('4 s');

  // Unticking keeps the feed in place until saved, so a mis-click is easy to undo.
  await page.locator(`#feed_${feedId}`).uncheck();
  await expect(page.locator('.ftc-enabled-list').locator(`#feed_${feedId}`)).not.toBeChecked();
  await submitConfigure(page, feedForm(page).getByRole('button', { name: 'Save feed settings' }));
  await gotoConfigure(page);
  await expect(page.locator('.ftc-add-list').locator(`#feed_${feedId}`)).not.toBeChecked();
});

test('the summary line shows which fetch options a feed overrides', async ({ page }) => {
  page.on('dialog', (dialog) => dialog.accept());
  const custom = await subscribe(page, uid('sum'));
  const plain = await subscribe(page, uid('sum'));
  await setFeedOptions(page, custom, { wait: '3', selector: '#main', stealth: true, waitUntil: 'networkidle0', timeout: '50' });
  await setFeedOptions(page, plain, {});

  await gotoConfigure(page);
  const summary = feedItem(page, custom).locator('.ftc-feed-summary');
  for (const part of ['#main', '3 s', 'network', '50 s', 'Stealth']) {
    await expect(summary).toContainText(part);
  }
  await expect(feedItem(page, plain).locator('.ftc-feed-summary')).toHaveText('Default fetch options');

  // The summary follows unsaved edits.
  await openFeedOptions(feedItem(page, plain));
  await feedItem(page, plain).getByLabel('Extra delay (seconds)').fill('7');
  await expect(feedItem(page, plain).locator('.ftc-feed-summary')).toContainText('7 s');
});

test('"Test" previews the extraction of the latest article with unsaved options and saves nothing', async ({ page, request }) => {
  const feed = uid('prev');
  const itemId = uid('late');
  const feedId = await subscribe(page, feed);
  await setFeedOptions(page, feedId, { enabled: false });
  await publish(request, feed, itemId, 'late');
  await refreshFeed(page, feedId);

  await gotoConfigure(page);
  // Leaving the page with unsaved changes asks for confirmation.
  page.on('dialog', (dialog) => dialog.accept());
  await enableInForm(page, feedId);
  const item = feedItem(page, feedId);
  await openFeedOptions(item);
  const result = item.locator('.ftc-test-result');
  const preview = item.frameLocator('iframe.ftc-test-preview').locator('body');

  // Default options: the late paragraph is not captured.
  await item.getByRole('button', { name: 'Test on latest article' }).click();
  await expect(result).toContainText('characters', { timeout: 90_000 });
  await expect(result).toContainText(itemId);
  await expect(preview).toContainText('This static paragraph is present immediately');
  await expect(preview).not.toContainText('LATE_CONTENT_PARAGRAPH');
  await expect(preview).not.toContainText('SITE_BANNER');

  // The unsaved selector is used by the next test run.
  await item.getByLabel('Wait for element (CSS selector)').fill('#late-content');
  await item.getByRole('button', { name: 'Test on latest article' }).click();
  await expect(preview).toContainText('LATE_CONTENT_PARAGRAPH', { timeout: 90_000 });

  // Testing does not save anything.
  await page.goto(CONFIGURE_URL);
  await expect(page.locator(`#feed_${feedId}`)).not.toBeChecked();
  await expect(page.locator(`input[name="fulltextcontent_selector[${feedId}]"]`)).toHaveValue('');
});

test('"Test" on a feed without articles explains what to do', async ({ page }) => {
  const feedId = await subscribe(page, uid('empty'));
  await gotoConfigure(page);
  await enableInForm(page, feedId);
  const item = feedItem(page, feedId);
  await openFeedOptions(item);
  await item.getByRole('button', { name: 'Test on latest article' }).click();
  await expect(item.locator('.ftc-test-result')).toContainText('no articles', { timeout: 30_000 });
  await expect(item.locator('.ftc-test-result')).toHaveClass(/ftc-error/);
});

test('rarely changed global settings are under "Advanced"', async ({ page }) => {
  await gotoConfigure(page);
  await expect(page.getByLabel('Fetch timeout (seconds)')).toBeVisible();
  await expect(page.getByLabel('Node.js binary path')).toBeHidden();
  await page.locator('.ftc-advanced > summary').click();
  await expect(page.getByLabel('Node.js binary path')).toBeVisible();
});

test('warnings are shown at the top of the page', async ({ page }) => {
  await saveGlobals(page, { defuddle_version: '0.19.4' });
  await gotoConfigure(page);
  const warningTop = await page.locator('.ftc-version-warning').first().evaluate((el) => el.getBoundingClientRect().top);
  const feedsTop = await page.getByRole('heading', { name: 'Feeds', exact: true }).evaluate((el) => el.getBoundingClientRect().top);
  expect(warningTop).toBeLessThan(feedsTop);
  await saveGlobals(page, DEFAULT_GLOBALS);
});

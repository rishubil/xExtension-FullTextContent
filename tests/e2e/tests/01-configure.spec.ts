import { test, expect } from '@playwright/test';
import {
  CONFIGURE_URL, DATA_DIR, DEFAULT_GLOBALS, DEFUDDLE_VERSION, OBSCURA_VERSION, OLD_OBSCURA_URL,
  feedForm, gotoConfigure, saveGlobals, setFeedOptions, statusCell, submitConfigure, subscribe, uid,
} from './helpers';


test('configure page shows global settings, status and per-feed sections', async ({ page }) => {
  await gotoConfigure(page);
  await expect(page.getByRole('heading', { name: /Full Text Content \(.+\) — Enabled/ })).toBeVisible();
  for (const label of ['Node.js binary path', 'Obscura download URL template', 'Obscura binary path override',
    'Defuddle version', 'Defuddle update check interval (hours)', 'Fetch timeout (seconds)',
    'Allow private network access']) {
    await expect(page.getByLabel(label, { exact: true })).toBeVisible();
  }
  await expect(page.getByRole('heading', { name: 'Status & Actions' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Update defuddle now' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Redownload obscura binary' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Per-feed Settings' })).toBeVisible();
});

test('global settings persist across reloads', async ({ page }) => {
  const custom = {
    node_binary: '/usr/bin/node',
    obscura_download_url: 'http://fixtures:8080/obscura/custom-{arch}.tar.gz',
    obscura_binary: '/opt/custom/obscura',
    defuddle_version: '0.19.4',
    defuddle_check_interval: '24',
    fetch_timeout: '45',
    allow_private_network: false,
  };
  await saveGlobals(page, custom);
  await page.goto(CONFIGURE_URL);
  for (const [key, value] of Object.entries(custom)) {
    if (typeof value === 'boolean') {
      await expect(page.locator(`#${key}`)).toBeChecked({ checked: value });
    } else {
      await expect(page.locator(`#${key}`)).toHaveValue(value);
    }
  }

  // Restore the defaults the rest of the suite relies on.
  await saveGlobals(page, DEFAULT_GLOBALS);
  await page.goto(CONFIGURE_URL);
  await expect(page.locator('#defuddle_version')).toHaveValue(DEFAULT_GLOBALS.defuddle_version);
  await expect(page.locator('#allow_private_network')).toBeChecked();
});

test('per-feed settings persist and saving them leaves global settings intact', async ({ page }) => {
  await saveGlobals(page, DEFAULT_GLOBALS);
  const feedId = await subscribe(page, uid('cfg'));
  await setFeedOptions(page, feedId, {
    enabled: true, wait: '2', waitUntil: 'networkidle0', selector: '#main', stealth: true, timeout: '25',
  });

  await page.goto(CONFIGURE_URL);
  const form = feedForm(page);
  await expect(form.locator(`#feed_${feedId}`)).toBeChecked();
  await expect(form.locator(`input[name="fulltextcontent_wait[${feedId}]"]`)).toHaveValue('2');
  await expect(form.locator(`select[name="fulltextcontent_wait_until[${feedId}]"]`)).toHaveValue('networkidle0');
  await expect(form.locator(`input[name="fulltextcontent_selector[${feedId}]"]`)).toHaveValue('#main');
  await expect(form.locator(`tr:has(#feed_${feedId}) input[name="fulltextcontent_stealth[]"]`)).toBeChecked();
  await expect(form.locator(`input[name="fulltextcontent_timeout[${feedId}]"]`)).toHaveValue('25');

  // Regression: the per-feed form must not wipe the global settings.
  await expect(page.locator('#obscura_download_url')).toHaveValue(DEFAULT_GLOBALS.obscura_download_url);
  await expect(page.locator('#defuddle_version')).toHaveValue(DEFAULT_GLOBALS.defuddle_version);
  await expect(page.locator('#fetch_timeout')).toHaveValue(DEFAULT_GLOBALS.fetch_timeout);
  await expect(page.locator('#allow_private_network')).toBeChecked();

  // ...and the global form must not wipe per-feed settings.
  await saveGlobals(page, DEFAULT_GLOBALS);
  await page.goto(CONFIGURE_URL);
  await expect(feedForm(page).locator(`#feed_${feedId}`)).toBeChecked();
  await expect(feedForm(page).locator(`input[name="fulltextcontent_selector[${feedId}]"]`)).toHaveValue('#main');

  // Clearing the options disables the feed again.
  await setFeedOptions(page, feedId, { enabled: false });
  await page.goto(CONFIGURE_URL);
  await expect(feedForm(page).locator(`#feed_${feedId}`)).not.toBeChecked();
  await expect(feedForm(page).locator(`input[name="fulltextcontent_selector[${feedId}]"]`)).toHaveValue('');
});

test('"Update defuddle now" installs the configured defuddle version', async ({ page }) => {
  await saveGlobals(page, DEFAULT_GLOBALS);
  // Cache a fresh copy of the page first: the action changes no user config,
  // so the status must still be up to date when the page is revisited.
  await gotoConfigure(page);
  await submitConfigure(page, page.getByRole('button', { name: 'Update defuddle now' }));
  await page.goto(CONFIGURE_URL);
  await expect(statusCell(page, 'Defuddle installed')).toHaveText(DEFUDDLE_VERSION);
});

test('"Redownload obscura binary" downloads obscura from the configured URL', async ({ page }) => {
  await saveGlobals(page, DEFAULT_GLOBALS);
  await gotoConfigure(page);
  await submitConfigure(page, page.getByRole('button', { name: 'Redownload obscura binary' }));
  await page.goto(CONFIGURE_URL);
  await expect(statusCell(page, 'Obscura binary')).toHaveText(`${DATA_DIR}/bin/obscura`);
  await expect(statusCell(page, 'Obscura version')).toContainText(OBSCURA_VERSION);
});

test('saving settings shows a confirmation', async ({ page }) => {
  await saveGlobals(page, DEFAULT_GLOBALS);
  await expect(page.locator('#notification')).toContainText('Settings saved.');

  const feedId = await subscribe(page, uid('notif'));
  await setFeedOptions(page, feedId, { enabled: false });
  await expect(page.locator('#notification')).toContainText('Settings saved.');
});

test('successful actions show a confirmation', async ({ page }) => {
  await saveGlobals(page, DEFAULT_GLOBALS);
  await submitConfigure(page, page.getByRole('button', { name: 'Update defuddle now' }));
  await expect(page.locator('#notification')).toContainText('Defuddle updated successfully.');

  await submitConfigure(page, page.getByRole('button', { name: 'Redownload obscura binary' }));
  await expect(page.locator('#notification')).toContainText('Obscura binary re-downloaded successfully.');
});

test('failed actions report the error instead of failing silently', async ({ page }) => {
  await saveGlobals(page, { obscura_download_url: 'http://fixtures:8080/obscura/missing-{arch}.tar.gz' });
  await submitConfigure(page, page.getByRole('button', { name: 'Redownload obscura binary' }));
  await expect(page.locator('#notification'))
    .toContainText('Failed to download obscura from http://fixtures:8080/obscura/missing-');
  await expect(page.locator('#notification')).toContainText(': HTTP 404');

  await saveGlobals(page, { defuddle_version: '0.0.0-does-not-exist' });
  await submitConfigure(page, page.getByRole('button', { name: 'Update defuddle now' }));
  await expect(page.locator('#notification')).toContainText('Failed to update defuddle: npm install failed:');
  await expect(page.locator('#notification')).toContainText('No matching version found for defuddle@0.0.0-does-not-exist');
  await expect(page.locator('#notification')).not.toContainText('npm ERR!');
  await expect(page.locator('#notification')).not.toContainText('_logs');

  // A failed redownload keeps the previously installed binary usable.
  await saveGlobals(page, DEFAULT_GLOBALS);
  await page.goto(CONFIGURE_URL);
  await expect(statusCell(page, 'Obscura version')).toContainText(OBSCURA_VERSION);
  await expect(statusCell(page, 'Defuddle installed')).toHaveText(DEFUDDLE_VERSION);
});

test.describe('minimum supported versions', () => {
  const warnings = (page: import('@playwright/test').Page) => page.locator('.ftc-version-warning');

  test.afterEach(async ({ page }) => {
    // Restore the supported binaries for the rest of the suite.
    await saveGlobals(page, DEFAULT_GLOBALS);
    await submitConfigure(page, page.getByRole('button', { name: 'Redownload obscura binary' }));
    await submitConfigure(page, page.getByRole('button', { name: 'Update defuddle now' }));
    await page.goto(CONFIGURE_URL);
    await expect(warnings(page)).toHaveCount(0);
  });

  test('no warning is shown for the supported versions', async ({ page }) => {
    await saveGlobals(page, DEFAULT_GLOBALS);
    await submitConfigure(page, page.getByRole('button', { name: 'Redownload obscura binary' }));
    await submitConfigure(page, page.getByRole('button', { name: 'Update defuddle now' }));
    await page.goto(CONFIGURE_URL);
    await expect(statusCell(page, 'Obscura version')).toContainText(OBSCURA_VERSION);
    await expect(statusCell(page, 'Defuddle installed')).toHaveText(DEFUDDLE_VERSION);
    await expect(warnings(page)).toHaveCount(0);
  });

  test('an obscura older than the minimum is flagged', async ({ page }) => {
    await saveGlobals(page, { obscura_download_url: OLD_OBSCURA_URL });
    await submitConfigure(page, page.getByRole('button', { name: 'Redownload obscura binary' }));
    await page.goto(CONFIGURE_URL);
    await expect(statusCell(page, 'Obscura version')).toContainText('0.1.0');
    await expect(warnings(page)).toHaveCount(1);
    await expect(warnings(page)).toContainText('obscura 0.1.0');
    await expect(warnings(page)).not.toContainText('obscura obscura');
    await expect(warnings(page)).toContainText(OBSCURA_VERSION);
  });

  test('an obscura binary override older than the minimum is flagged', async ({ page }) => {
    // Install the old stub, then point the override at it; the downloaded
    // path is replaced by the real binary afterwards.
    await saveGlobals(page, { obscura_download_url: OLD_OBSCURA_URL });
    await submitConfigure(page, page.getByRole('button', { name: 'Redownload obscura binary' }));
    await saveGlobals(page, { obscura_binary: `${DATA_DIR}/bin/obscura` });
    await page.goto(CONFIGURE_URL);
    await expect(statusCell(page, 'Obscura binary')).toHaveText(`${DATA_DIR}/bin/obscura`);
    await expect(warnings(page)).toContainText('obscura 0.1.0');
  });

  test('a defuddle pinned or installed below the minimum is flagged', async ({ page }) => {
    await saveGlobals(page, { defuddle_version: '0.19.4' });
    await page.goto(CONFIGURE_URL);
    // Configured pin is below the minimum (installed one is still fine).
    await expect(warnings(page)).toHaveCount(1);
    await expect(warnings(page)).toContainText('0.19.4');
    await expect(warnings(page)).toContainText(DEFUDDLE_VERSION);

    await submitConfigure(page, page.getByRole('button', { name: 'Update defuddle now' }));
    await page.goto(CONFIGURE_URL);
    await expect(statusCell(page, 'Defuddle installed')).toHaveText('0.19.4');
    // Both the pin and the installed package are now below the minimum.
    await expect(warnings(page)).toHaveCount(2);
  });
});

test('the configure page does not overflow horizontally at phone width', async ({ page }) => {
  await subscribe(page, uid('narrow'));
  await page.setViewportSize({ width: 390, height: 844 });
  await gotoConfigure(page);
  const widths = await page.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
  expect(widths[0]).toBeLessThanOrEqual(widths[1]);
  // The wide per-feed table scrolls inside its own container instead.
  const lastStealth = feedForm(page).locator('input[name="fulltextcontent_stealth[]"]').last();
  await lastStealth.scrollIntoViewIfNeeded();
  await expect(lastStealth).toBeInViewport();
});

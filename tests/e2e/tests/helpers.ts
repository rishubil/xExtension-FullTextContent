import { expect, type APIRequestContext, type Locator, type Page } from '@playwright/test';

export const FIXTURES_URL = process.env.FIXTURES_URL ?? 'http://localhost:18081';
/** Base URL of the fixture server as seen from inside the FreshRSS container. */
export const FIXTURES_INTERNAL_URL = 'http://fixtures:8080';
export const CONFIGURE_URL = '/i/?c=extension&a=configure&e=Full%20Text%20Content';
export const DATA_DIR = '/var/www/FreshRSS/data/fulltextcontent';
export const DEFUDDLE_VERSION = '0.19.5';
export const OBSCURA_VERSION = '0.2.4';
export const OLD_OBSCURA_URL = `${'http://fixtures:8080'}/obscura/obscura-old-{arch}.tar.gz`;
export const ADMIN = { user: 'admin', password: 'e2e-password' };

export type Article = 'standard' | 'js-rendered' | 'late' | 'counter';

export interface GlobalSettings {
  node_binary: string;
  obscura_download_url: string;
  obscura_binary: string;
  defuddle_version: string;
  defuddle_check_interval: string;
  fetch_timeout: string;
  allow_private_network: boolean;
  obscura_stealth_build: boolean;
}

export const DEFAULT_GLOBALS: GlobalSettings = {
  node_binary: 'node',
  obscura_download_url: `${FIXTURES_INTERNAL_URL}/obscura/obscura-{arch}{variant}.tar.gz`,
  obscura_binary: '',
  defuddle_version: DEFUDDLE_VERSION,
  defuddle_check_interval: '168',
  fetch_timeout: '60',
  allow_private_network: true,
  obscura_stealth_build: false,
};

export interface FeedOptions {
  enabled?: boolean;
  wait?: string;
  waitUntil?: string;
  selector?: string;
  stealth?: boolean;
  timeout?: string;
}

/** Short unique token so every test works on its own feed and items. */
export function uid(prefix: string): string {
  return `${prefix}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`;
}

export async function publish(request: APIRequestContext, feed: string, id: string, article: Article): Promise<void> {
  const res = await request.post(`${FIXTURES_URL}/_control/publish`, { data: { feed, id, article } });
  expect(res.ok()).toBeTruthy();
}

export async function setFailing(request: APIRequestContext, id: string, fail: boolean): Promise<void> {
  const res = await request.post(`${FIXTURES_URL}/_control/fail`, { data: { id, fail } });
  expect(res.ok()).toBeTruthy();
}

export async function login(page: Page): Promise<void> {
  await page.goto('/i/');
  await page.getByLabel('Username').fill(ADMIN.user);
  await page.getByLabel('Password', { exact: true }).fill(ADMIN.password);
  await page.getByRole('button', { name: 'Login' }).click();
  await expect(page.getByRole('link', { name: 'Subscription management' })).toBeVisible();
}

/** Subscribes to fixture feed `name` and returns the FreshRSS feed id. */
export async function subscribe(page: Page, name: string): Promise<string> {
  await page.goto('/i/?c=subscription&a=add');
  const form = page.locator('form').filter({ has: page.locator('input[name="url_rss"]') });
  await form.locator('input[name="url_rss"]').fill(`${FIXTURES_INTERNAL_URL}/feeds/${name}.xml`);
  await form.getByRole('button', { name: 'Add' }).click();
  await page.waitForURL(/c=subscription&a=feed&id=\d+/);
  const id = new URL(page.url()).searchParams.get('id');
  expect(id).toBeTruthy();
  return id!;
}

export async function refreshFeed(page: Page, feedId: string): Promise<void> {
  await page.goto(`/i/?c=subscription&a=feed&id=${feedId}`);
  // FreshRSS 1.29 renders a GET link, 1.30+ a POST button (formaction).
  await page.locator(`a[href*="a=actualize&id=${feedId}"], button[formaction*="a=actualize&id=${feedId}"]`).first().click();
  await page.waitForURL(/get=f_\d+/);
}

/**
 * FreshRSS answers GETs with 304 when the user's config/log mtime is not newer
 * than the browser's cached Last-Modified, and mtimes have one-second
 * resolution. Writes in the same second as a cached read would therefore be
 * invisible to the next navigation; keep writes and reads in distinct seconds.
 */
export async function nextSecond(page: Page): Promise<void> {
  await page.waitForTimeout(1_100);
}

/** Clicks a configure-page submit button and waits until FreshRSS answered the POST. */
export async function submitConfigure(page: Page, button: Locator): Promise<void> {
  await nextSecond(page);
  await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('a=configure')),
    button.click(),
  ]);
  await page.waitForLoadState('load');
  await nextSecond(page);
}

export async function gotoConfigure(page: Page): Promise<void> {
  await page.goto(CONFIGURE_URL);
  await expect(page.getByRole('heading', { name: 'Global Settings' })).toBeVisible();
}

/** Opens the configure page without relying on English headings. */
export async function gotoConfigureAnyLanguage(page: Page): Promise<void> {
  await page.goto(CONFIGURE_URL);
  await expect(page.locator('.ftc-configure')).toBeVisible();
}

function globalForm(page: Page): Locator {
  return page.locator('form').filter({ has: page.locator('input[name="fulltextcontent_action"][value="save_global"]') });
}

export function feedForm(page: Page): Locator {
  return page.locator('form').filter({ has: page.locator('input[name="fulltextcontent_action"][value="save_feeds"]') });
}

export async function saveGlobals(page: Page, values: Partial<GlobalSettings> = {}): Promise<void> {
  const settings = { ...DEFAULT_GLOBALS, ...values };
  await gotoConfigure(page);
  const form = globalForm(page);
  await openDetails(form.locator('details.ftc-advanced'));
  for (const key of ['node_binary', 'obscura_download_url', 'obscura_binary', 'defuddle_version',
    'defuddle_check_interval', 'fetch_timeout'] as const) {
    await form.locator(`#${key}`).fill(settings[key]);
  }
  await form.locator('#allow_private_network').setChecked(settings.allow_private_network);
  await form.locator('#obscura_stealth_build').setChecked(settings.obscura_stealth_build);
  await submitConfigure(page, form.getByRole('button', { name: 'Save' }));
}

/** The list item of a feed in the per-feed settings form. */
export function feedItem(page: Page, feedId: string): Locator {
  return page.locator(`.ftc-feed[data-feed-id="${feedId}"]`);
}

/** Opens a <details> element and every <details> it is nested in. */
async function openDetails(details: Locator): Promise<void> {
  await details.evaluate((el) => {
    for (let d: Element | null = el; d; d = d.parentElement?.closest('details') ?? null) {
      (d as HTMLDetailsElement).open = true;
    }
  });
}

/** Expands the fetch options panel of a feed list item. */
export async function openFeedOptions(item: Locator): Promise<void> {
  await openDetails(item.locator('details.ftc-feed-options'));
}

/** Ticks a feed in the form (moving it to the enabled feeds) without saving. */
export async function enableInForm(page: Page, feedId: string): Promise<void> {
  const box = page.locator(`#feed_${feedId}`);
  if (!(await box.isChecked())) {
    await openDetails(page.locator('details.ftc-add-panel'));
    await box.check();
  }
  await expect(page.locator('.ftc-enabled-list').locator(`#feed_${feedId}`)).toBeChecked();
}

export async function setFeedOptions(page: Page, feedId: string, options: FeedOptions): Promise<void> {
  await gotoConfigure(page);
  const form = feedForm(page);
  if (options.enabled === false) {
    // Disabled feeds keep their stored options; only the checkbox changes.
    // Feeds that are not enabled yet sit in the collapsed "Add feeds" picker.
    if (await page.locator('.ftc-add-list').locator(`#feed_${feedId}`).count() > 0) {
      await openDetails(page.locator('details.ftc-add-panel'));
    }
    await page.locator(`#feed_${feedId}`).setChecked(false);
  } else {
    await enableInForm(page, feedId);
    const item = feedItem(page, feedId);
    await openFeedOptions(item);
    await item.locator(`input[name="fulltextcontent_wait[${feedId}]"]`).fill(options.wait ?? '');
    await item.locator(`select[name="fulltextcontent_wait_until[${feedId}]"]`).selectOption(options.waitUntil ?? '');
    await item.locator(`input[name="fulltextcontent_selector[${feedId}]"]`).fill(options.selector ?? '');
    await item.locator('input[name="fulltextcontent_stealth[]"]').setChecked(options.stealth ?? false);
    await item.locator(`input[name="fulltextcontent_timeout[${feedId}]"]`).fill(options.timeout ?? '');
  }
  await submitConfigure(page, form.getByRole('button', { name: 'Save feed settings' }));
}

/** Creates a feed with full-text enabled (or not), publishes one item, refreshes. */
export async function feedWithItem(
  page: Page, request: APIRequestContext, article: Article, options: FeedOptions = {},
): Promise<{ feedId: string; itemId: string }> {
  const feed = uid('feed');
  const itemId = uid(article);
  const feedId = await subscribe(page, feed);
  await setFeedOptions(page, feedId, options);
  await publish(request, feed, itemId, article);
  await refreshFeed(page, feedId);
  return { feedId, itemId };
}

/** Opens the feed view and returns the `.flux` element of the given item. */
export async function openEntry(page: Page, feedId: string, itemId: string): Promise<Locator> {
  await page.goto(`/i/?get=f_${feedId}`);
  const entry = page.locator(`.flux[data-link$="id=${itemId}"]`);
  await expect(entry).toHaveCount(1);
  return entry;
}

export async function csrfToken(page: Page): Promise<string> {
  return page.evaluate(() => (window as unknown as { context: { csrf: string } }).context.csrf);
}

export function entryText(entry: Locator): Locator {
  return entry.locator('.flux_content .text');
}

/** Returns the status table cell for a row header such as "Obscura version". */
export function statusCell(page: Page, header: string): Locator {
  return page.locator('tr').filter({ has: page.getByRole('rowheader', { name: header, exact: true }) }).locator('td');
}

export async function fixtureHits(request: APIRequestContext, id: string): Promise<number> {
  const res = await request.get(`${FIXTURES_URL}/_control/hits?id=${encodeURIComponent(id)}`);
  return (await res.json()).hits as number;
}

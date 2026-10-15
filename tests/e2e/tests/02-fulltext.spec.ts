import { test, expect, type Locator, type Page } from '@playwright/test';
import {
  DEFAULT_GLOBALS, csrfToken, entryText, feedWithItem, nextSecond, openEntry, publish, refreshFeed, saveGlobals,
  setFailing, setFeedOptions, subscribe, uid,
} from './helpers';


test.beforeAll(async ({ browser }) => {
  const page = await browser.newPage({ storageState: '.auth/admin.json' });
  await saveGlobals(page, DEFAULT_GLOBALS);
  await page.close();
});

async function expand(page: Page, entry: Locator): Promise<void> {
  await entry.locator('.flux_header .item.titleAuthorSummaryDate').click();
  await expect(entry).toHaveClass(/active/);
}

test('refresh replaces the entry content with the extracted full text', async ({ page, request }) => {
  const { feedId, itemId } = await feedWithItem(page, request, 'standard');
  const text = entryText(await openEntry(page, feedId, itemId));

  await expect(text).toContainText('FULLTEXT_PARAGRAPH_ONE');
  await expect(text).toContainText('FULLTEXT_PARAGRAPH_LAST');
  await expect(text).not.toContainText(`RSS_SUMMARY_${itemId}`);
  await expect(text.locator('strong')).toHaveText('bold words');
  await expect(text.locator('em')).toHaveText('italic words');
  await expect(text.locator('h2')).toHaveText('Second section');
  for (const marker of ['SITE_BANNER', 'SIDEBAR_LINK', 'FOOTER_SHOULD', 'SCRIPT_SHOULD']) {
    await expect(text).not.toContainText(marker);
  }
  // Relative URLs are resolved against the article URL. (The entry is
  // collapsed, so query the DOM rather than the accessibility tree.)
  await expect(text.locator('a', { hasText: 'user guide' })).toHaveAttribute('href', 'http://fixtures:8080/docs/guide.html');
  await expect(text.locator('a', { hasText: 'team page' })).toHaveAttribute('href', 'http://fixtures:8080/about/team');
  // FreshRSS lazy-loads images: the real URL lives in data-original.
  await expect(text.locator('img[alt="fixture pixel"]')).toHaveAttribute('data-original', 'http://fixtures:8080/static/pixel.png');
});

test('JavaScript is executed before extraction', async ({ page, request }) => {
  const { feedId, itemId } = await feedWithItem(page, request, 'js-rendered');
  const text = entryText(await openEntry(page, feedId, itemId));
  await expect(text).toContainText('JS_RENDERED_PARAGRAPH');
  await expect(text).not.toContainText('SITE_BANNER');
});

test('feeds without full text enabled keep the feed summary and have no re-fetch button', async ({ page, request }) => {
  const { feedId, itemId } = await feedWithItem(page, request, 'standard', { enabled: false });
  const entry = await openEntry(page, feedId, itemId);
  await expect(entryText(entry)).toHaveText(`RSS_SUMMARY_${itemId}`);
  await expect(entry.locator('.ftc-refetch')).toHaveCount(0);
});

// obscura returns as soon as the page is quiescent and does not wait for the
// 3 s timer in late.html, so the late paragraph is only captured when a
// per-feed option tells it to wait.
test('without per-feed fetch options, content inserted late is not captured', async ({ page, request }) => {
  const { feedId, itemId } = await feedWithItem(page, request, 'late');
  const text = entryText(await openEntry(page, feedId, itemId));
  await expect(text).toContainText('This static paragraph is present immediately');
  await expect(text).not.toContainText('LATE_CONTENT_PARAGRAPH');
});

test('the per-feed selector option waits for late content', async ({ page, request }) => {
  const { feedId, itemId } = await feedWithItem(page, request, 'late', { selector: '#late-content' });
  await expect(entryText(await openEntry(page, feedId, itemId))).toContainText('LATE_CONTENT_PARAGRAPH');
});

test('the per-feed wait option delays the capture', async ({ page, request }) => {
  const { feedId, itemId } = await feedWithItem(page, request, 'late', { wait: '5' });
  await expect(entryText(await openEntry(page, feedId, itemId))).toContainText('LATE_CONTENT_PARAGRAPH');
});

test('extraction works with every per-feed fetch option set', async ({ page, request }) => {
  const { feedId, itemId } = await feedWithItem(page, request, 'late', {
    selector: '#late-content', waitUntil: 'domcontentloaded', stealth: true, timeout: '20',
  });
  await expect(entryText(await openEntry(page, feedId, itemId))).toContainText('LATE_CONTENT_PARAGRAPH');
});

test('the feed summary is kept when the article cannot be fetched at refresh time', async ({ page, request }) => {
  const feed = uid('feed');
  const itemId = uid('standard');
  const feedId = await subscribe(page, feed);
  await setFeedOptions(page, feedId, {});
  await setFailing(request, itemId, true);
  await publish(request, feed, itemId, 'standard');
  await refreshFeed(page, feedId);
  const text = entryText(await openEntry(page, feedId, itemId));
  await expect(text).toContainText(`RSS_SUMMARY_${itemId}`);
  await expect(text).not.toContainText('FULLTEXT_PARAGRAPH_ONE');
  await setFailing(request, itemId, false);
});

test('the re-fetch button updates the entry content and the update is persisted', async ({ page, request }) => {
  const { feedId, itemId } = await feedWithItem(page, request, 'counter');
  let entry = await openEntry(page, feedId, itemId);
  await expect(entryText(entry)).toContainText(`FETCH_COUNT_1_FOR_${itemId}`);

  await expand(page, entry);
  const bar = entry.locator('.ftc-refetch');
  await bar.getByRole('link', { name: 'Re-fetch Content' }).click();
  await expect(bar.locator('.ftc-status')).toHaveText('Content updated.');
  await expect(bar).toHaveClass(/ftc-success/);
  await expect(entryText(entry)).toContainText(`FETCH_COUNT_2_FOR_${itemId}`);
  await expect(entryText(entry)).not.toContainText(`FETCH_COUNT_1_FOR_${itemId}`);

  entry = await openEntry(page, feedId, itemId);
  await expect(entryText(entry)).toContainText(`FETCH_COUNT_2_FOR_${itemId}`);
  await expect(entry.locator('.ftc-refetch')).toHaveCount(1);
});

test('the re-fetch button reports an error and keeps the content when the fetch fails', async ({ page, request }) => {
  const { feedId, itemId } = await feedWithItem(page, request, 'counter');
  const entry = await openEntry(page, feedId, itemId);
  await expect(entryText(entry)).toContainText(`FETCH_COUNT_1_FOR_${itemId}`);

  await setFailing(request, itemId, true);
  await expand(page, entry);
  const bar = entry.locator('.ftc-refetch');
  await bar.getByRole('link', { name: 'Re-fetch Content' }).click();
  await expect(bar).toHaveClass(/ftc-error/);
  // The message names the article, not the pipeline's temporary file.
  await expect(bar.locator('.ftc-status')).toContainText(`http://fixtures:8080/articles/counter?id=${itemId}`);
  await expect(bar.locator('.ftc-status')).not.toContainText('/tmp/');
  await expect(entryText(entry)).toContainText(`FETCH_COUNT_1_FOR_${itemId}`);
  await setFailing(request, itemId, false);
});

test('re-fetched content is shown after reloading, not a stale cached page', async ({ page, request }) => {
  const { feedId, itemId } = await feedWithItem(page, request, 'counter');
  const entry = await openEntry(page, feedId, itemId);
  await expect(entryText(entry)).toContainText(`FETCH_COUNT_1_FOR_${itemId}`);
  const entryId = await entry.getAttribute('data-entry');

  // Re-fetch without expanding the entry (expanding marks it read, which
  // would refresh FreshRSS's Last-Modified on its own).
  await nextSecond(page);
  const res = await page.request.post(`/i/?c=fullTextContent&a=refetch&id=${entryId}`, {
    form: { _csrf: await csrfToken(page) },
  });
  expect(await res.json()).toMatchObject({ status: 200 });

  await page.reload();
  await expect(entryText(page.locator(`.flux[data-entry="${entryId}"]`))).toContainText(`FETCH_COUNT_2_FOR_${itemId}`);
});

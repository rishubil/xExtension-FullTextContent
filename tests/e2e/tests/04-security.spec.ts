import { test, expect, type Page } from '@playwright/test';
import { csrfToken, feedWithItem, fixtureHits, openEntry } from './helpers';

// FreshRSS itself rejects unauthenticated and CSRF-less POSTs before the
// extension controller runs (redirect to the error page). What matters is that
// such requests never trigger a fetch of the article nor change the entry.

async function enabledEntry(page: Page, request: Parameters<typeof feedWithItem>[1]) {
  const { feedId, itemId } = await feedWithItem(page, request, 'counter');
  const entry = await openEntry(page, feedId, itemId);
  const entryId = await entry.getAttribute('data-entry');
  expect(entryId).toBeTruthy();
  return { itemId, refetch: `/i/?c=fullTextContent&a=refetch&id=${entryId}` };
}

test('refetch rejects unauthenticated requests without fetching', async ({ page, request, playwright, baseURL }) => {
  const { itemId, refetch } = await enabledEntry(page, request);
  const before = await fixtureHits(request, itemId);

  const anonymous = await playwright.request.newContext({ baseURL });
  const res = await anonymous.post(refetch, { maxRedirects: 0 });
  expect(res.status()).toBe(302);
  expect(res.headers()['location']).toContain('c=error');
  await anonymous.dispose();

  expect(await fixtureHits(request, itemId)).toBe(before);
});

test('refetch rejects POST requests without a valid CSRF token without fetching', async ({ page, request }) => {
  const { itemId, refetch } = await enabledEntry(page, request);
  const before = await fixtureHits(request, itemId);

  const res = await page.request.post(refetch, { form: { _csrf: 'invalid' }, maxRedirects: 0 });
  expect(res.status()).toBe(302);
  expect(res.headers()['location']).toContain('c=error');

  expect(await fixtureHits(request, itemId)).toBe(before);
});

test('refetch requires POST', async ({ page, request }) => {
  const { itemId, refetch } = await enabledEntry(page, request);
  const before = await fixtureHits(request, itemId);
  const res = await page.request.get(refetch, { maxRedirects: 0 });
  expect(await res.json()).toMatchObject({ status: 405 });
  expect(await fixtureHits(request, itemId)).toBe(before);
});

test('refetch returns 404 for an unknown entry', async ({ page }) => {
  await page.goto('/i/');
  const csrf = await csrfToken(page);
  const res = await page.request.post('/i/?c=fullTextContent&a=refetch&id=999999999', { form: { _csrf: csrf } });
  expect(await res.json()).toMatchObject({ status: 404 });
});

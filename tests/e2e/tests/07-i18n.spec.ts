import { test, expect, type Page } from '@playwright/test';
import { DEFAULT_GLOBALS, feedWithItem, gotoConfigureAnyLanguage, openEntry, saveGlobals } from './helpers';

async function setLanguage(page: Page, language: string): Promise<void> {
  await page.goto('/i/?c=configure&a=display');
  const select = page.locator('select[name="language"]');
  await select.selectOption(language);
  // FreshRSS redirects back to the display settings (with ?rid=) after saving.
  await Promise.all([
    page.waitForURL(/a=display.*rid=/),
    select.evaluate((el) => (el as HTMLSelectElement).form!.requestSubmit()),
  ]);
}

test.beforeAll(async ({ browser }) => {
  const page = await browser.newPage({ storageState: '.auth/admin.json' });
  await saveGlobals(page, DEFAULT_GLOBALS);
  await page.close();
});

test.afterEach(async ({ page }) => {
  await setLanguage(page, 'en');
});

test('the configure page and the re-fetch button are translated to Korean', async ({ page, request }) => {
  const { feedId, itemId } = await feedWithItem(page, request, 'counter');
  await setLanguage(page, 'ko');

  await gotoConfigureAnyLanguage(page);
  await expect(page.getByRole('heading', { name: '피드', exact: true })).toBeVisible();
  await expect(page.getByRole('heading', { name: '전역 설정' })).toBeVisible();
  await expect(page.getByRole('button', { name: '피드 설정 저장' })).toBeVisible();

  const entry = await openEntry(page, feedId, itemId);
  await entry.locator('.flux_header .item.titleAuthorSummaryDate').click();
  const bar = entry.locator('.ftc-refetch');
  await bar.getByRole('link', { name: '전문 다시 가져오기' }).click();
  await expect(bar.locator('.ftc-status')).toHaveText('본문을 업데이트했습니다.');
});

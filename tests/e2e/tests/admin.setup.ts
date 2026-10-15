import { test as setup, expect } from '@playwright/test';
import { login } from './helpers';

setup('log in and enable the extension from the admin UI', async ({ page }) => {
  await login(page);

  await page.goto('/i/?c=extension');
  const item = page.locator('li').filter({ has: page.locator('.ext_name', { hasText: /^Full Text Content$/ }) });
  await expect(item).toHaveCount(1);
  if (await item.locator('.ext_name.disabled').count()) {
    await item.locator('button.switch').click();
    await page.waitForLoadState('load');
  }
  await expect(item.locator('.ext_name')).not.toHaveClass(/disabled/);

  await page.context().storageState({ path: '.auth/admin.json' });
});

import { expect, test } from '@playwright/test';

const pages = [
  { name: 'home', path: '/index.html' },
  { name: 'category', path: '/category.html' },
  { name: 'product', path: '/product.html?slug=e2e-test-serum' },
  { name: 'cart', path: '/cart.html' },
  { name: 'login', path: '/login.html' },
  { name: 'admin-login', path: '/admin/login.html' },
];

const viewports = [
  { name: 'mobile', width: 360, height: 800 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'desktop', width: 1440, height: 900 },
];

for (const viewport of viewports) {
  test.describe(`${viewport.name} responsive QA`, () => {
    test.use({ viewport: { width: viewport.width, height: viewport.height } });

    for (const target of pages) {
      test(`${target.name} has no horizontal overflow or runtime page errors`, async ({ page }) => {
        const pageErrors = [];
        page.on('pageerror', (error) => pageErrors.push(error.message));

        const response = await page.goto(target.path, { waitUntil: 'networkidle' });
        expect(response?.ok(), `${target.path} should load successfully`).toBeTruthy();

        await expect(page.locator('body')).toBeVisible();

        const dimensions = await page.evaluate(() => ({
          viewportWidth: window.innerWidth,
          documentWidth: document.documentElement.scrollWidth,
          bodyWidth: document.body.scrollWidth,
        }));

        expect(
          dimensions.documentWidth,
          `${target.path} document overflows at ${viewport.width}px`,
        ).toBeLessThanOrEqual(dimensions.viewportWidth + 1);
        expect(
          dimensions.bodyWidth,
          `${target.path} body overflows at ${viewport.width}px`,
        ).toBeLessThanOrEqual(dimensions.viewportWidth + 1);
        expect(pageErrors, `${target.path} emitted browser page errors`).toEqual([]);
      });
    }
  });
}

test('cart flow allows a guest to abandon purchase and clear the cart page', async ({ page }) => {
  await page.addInitScript(() => {
    localStorage.setItem('sharafi_guest_cart_v2', JSON.stringify([{
      variant_id: 101,
      qty: 2,
      name: 'سرم تست مهمان',
      slug: 'guest-serum',
      variant_title: '۳۰ میل',
      price: 250000,
      icon: '🧴',
      in_stock: true,
    }]));
  });
  await page.goto('/cart.html', { waitUntil: 'networkidle' });

  await expect(page.getByText('سرم تست مهمان', { exact: true })).toBeVisible();
  await expect(page.locator('.js-cart-count').first()).toHaveText('۲');

  page.once('dialog', async (dialog) => {
    expect(dialog.message()).toContain('سبد خرید خالی شود');
    await dialog.accept();
  });
  await page.locator('.js-cart-clear').click();

  await expect(page.getByRole('heading', { name: 'سبد خرید شما خالی است' })).toBeVisible();
  await expect(page.locator('.js-cart-clear')).toBeHidden();
  await expect(page.evaluate(() => JSON.parse(localStorage.getItem('sharafi_guest_cart_v2') || '[]'))).resolves.toEqual([]);
});

test('cart flow shows checkout return notice while preserving a guest cart at login', async ({ page }) => {
  await page.addInitScript(() => {
    localStorage.setItem('sharafi_guest_cart_v2', JSON.stringify([{
      variant_id: 202,
      qty: 3,
      name: 'کرم تست مهمان',
      slug: 'guest-cream',
      variant_title: '۵۰ میل',
      price: 190000,
      icon: '🧴',
      in_stock: true,
    }]));
  });
  await page.goto('/login.html?return=checkout.html', { waitUntil: 'networkidle' });

  await expect(page.locator('.js-auth-return-notice')).toBeVisible();
  await expect(page.locator('.js-auth-return-notice')).toContainText('۳ قلم از سبد شما حفظ می‌شود');
  await expect(page.getByRole('button', { name: 'دریافت کد ورود' })).toBeVisible();
});

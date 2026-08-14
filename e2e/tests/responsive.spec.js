import { expect, test } from '@playwright/test';

const pages = [
  { name: 'home', path: '/index.html' },
  { name: 'category', path: '/category.html' },
  { name: 'product', path: '/product.html?slug=e2e-test-serum' },
  { name: 'cart', path: '/cart.html' },
  { name: 'checkout', path: '/checkout.html' },
  { name: 'payment-result', path: '/payment-result.html' },
  { name: 'account', path: '/account.html' },
  { name: 'login', path: '/login.html' },
  { name: 'admin-login', path: '/admin/login.html' },
  { name: 'admin-dashboard', path: '/admin/dashboard.html' },
  { name: 'admin-products', path: '/admin/products.html' },
  { name: 'admin-inventory', path: '/admin/inventory.html' },
  { name: 'admin-orders', path: '/admin/orders.html' },
  { name: 'admin-discounts', path: '/admin/discounts.html' },
  { name: 'admin-customers', path: '/admin/users.html' },
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

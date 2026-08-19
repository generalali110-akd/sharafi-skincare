const { expect, test } = require('@playwright/test');

async function abortCatalog(page) {
  await page.route('http://127.0.0.1:8000/api/v1/catalog/**', (route) => route.abort());
}

test.describe('storefront API fallback', () => {
  test('category replaces static sample cards with a catalog connection error', async ({ page }) => {
    await abortCatalog(page);
    await page.goto('/category.html', { waitUntil: 'networkidle' });

    await expect(page.getByRole('heading', { name: 'اتصال به کاتالوگ برقرار نیست' })).toBeVisible();
    await expect(page.locator('.category-main .product-card-v2')).toHaveCount(0);
  });

  test('home replaces static sample cards with a catalog connection error', async ({ page }) => {
    await abortCatalog(page);
    await page.goto('/index.html', { waitUntil: 'networkidle' });

    await expect(page.getByRole('heading', { name: 'اتصال به کاتالوگ برقرار نیست' })).toBeVisible();
    await expect(page.locator('.hero ~ .section .product-card-v2')).toHaveCount(0);
  });

  test('product replaces static detail and related cards with a catalog connection error', async ({ page }) => {
    await abortCatalog(page);
    await page.goto('/product.html?slug=e2e-test-serum', { waitUntil: 'networkidle' });

    await expect(page.getByRole('heading', { name: 'اتصال به کاتالوگ برقرار نیست' })).toBeVisible();
    await expect(page.locator('.product-detail-layout .product-buy-btn')).toHaveCount(0);
    await expect(page.locator('.product-page .section .product-card-v2')).toHaveCount(0);
    await expect(page.locator('.mobile-purchase-bar')).toBeHidden();
  });
});

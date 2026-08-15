const { test, expect } = require('@playwright/test');
const { waitForOtp, clearOtp, enterOtp } = require('./helpers');

const CUSTOMER_MOBILE = '09120000002';
const ADMIN_MOBILE = '09120000003';

const viewports = [
  { name: 'mobile', width: 360, height: 800 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'desktop', width: 1440, height: 900 },
];

async function assertResponsive(page, path, width) {
  const pageErrors = [];
  const onPageError = (error) => pageErrors.push(error.message);
  page.on('pageerror', onPageError);

  try {
    const response = await page.goto(path, { waitUntil: 'networkidle' });
    expect(response?.ok(), `${path} should load successfully`).toBeTruthy();
    await expect(page.locator('body')).toBeVisible();

    const dimensions = await page.evaluate(() => ({
      viewportWidth: window.innerWidth,
      documentWidth: document.documentElement.scrollWidth,
      bodyWidth: document.body.scrollWidth,
    }));

    expect(dimensions.documentWidth, `${path} document overflows at ${width}px`)
      .toBeLessThanOrEqual(dimensions.viewportWidth + 1);
    expect(dimensions.bodyWidth, `${path} body overflows at ${width}px`)
      .toBeLessThanOrEqual(dimensions.viewportWidth + 1);
    expect(pageErrors, `${path} emitted browser page errors`).toEqual([]);
  } finally {
    page.off('pageerror', onPageError);
  }
}

async function loginCustomer(page) {
  await clearOtp(CUSTOMER_MOBILE);
  await page.goto('/login.html');
  await page.locator('#login-mobile').fill(CUSTOMER_MOBILE);
  await page.locator('[data-auth-view="login"] .js-auth-request-otp').click();
  const otp = await waitForOtp(CUSTOMER_MOBILE);
  await enterOtp(page, otp);
  await page.locator('[data-auth-view="otp"] .js-auth-verify').click();
  await page.waitForURL((url) => !url.pathname.endsWith('/login.html'));
}

async function loginAdmin(page) {
  await clearOtp(ADMIN_MOBILE);
  await page.goto('/admin/login.html');
  await page.locator('#admin-mobile').fill(ADMIN_MOBILE);
  await page.locator('.js-admin-submit').click();
  const otp = await waitForOtp(ADMIN_MOBILE);
  await page.locator('#admin-code').fill(otp);
  await page.locator('.js-admin-submit').click();
  await expect(page).toHaveURL(/\/admin\/dashboard\.html$/);
  await expect(page.locator('html')).toHaveAttribute('data-admin-authorized', 'true');
}

test.describe.serial('authenticated responsive release surfaces', () => {
  test('customer account and checkout surfaces stay responsive across release viewports', async ({ page }) => {
    await loginCustomer(page);

    for (const viewport of viewports) {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });

      for (const path of ['/account.html', '/checkout.html', '/payment-result.html']) {
        await assertResponsive(page, path, viewport.width);
      }
    }
  });

  test('admin surfaces stay responsive after authorization across release viewports', async ({ page }) => {
    await loginAdmin(page);

    for (const viewport of viewports) {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });

      for (const path of [
        '/admin/dashboard.html',
        '/admin/products.html',
        '/admin/inventory.html',
        '/admin/orders.html',
        '/admin/discounts.html',
        '/admin/users.html',
      ]) {
        await assertResponsive(page, path, viewport.width);
        await expect(page.locator('html')).toHaveAttribute('data-admin-authorized', 'true');
      }
    }
  });
});

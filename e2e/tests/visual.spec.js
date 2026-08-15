const fs = require('node:fs');
const path = require('node:path');
const { expect, test } = require('@playwright/test');
const { waitForOtp, clearOtp, enterOtp } = require('./helpers');

const FIXED_TIME = Date.parse('2026-08-13T08:00:00.000Z');
const outputDirectory = path.resolve(process.cwd(), 'test-results', 'visual-current');
const CUSTOMER_MOBILE = '09120000002';
const ADMIN_MOBILE = '09120000003';

const targets = [
  { name: 'home', path: '/index.html' },
  { name: 'category', path: '/category.html' },
  { name: 'product', path: '/product.html?slug=e2e-test-serum' },
  { name: 'login', path: '/login.html' },
  { name: 'admin-login', path: '/admin/login.html' },
];

const customerTargets = [
  { name: 'account', path: '/account.html' },
  { name: 'checkout', path: '/checkout.html' },
  { name: 'payment-result', path: '/payment-result.html' },
];

const adminTargets = [
  { name: 'admin-dashboard', path: '/admin/dashboard.html' },
  { name: 'admin-products', path: '/admin/products.html' },
  { name: 'admin-inventory', path: '/admin/inventory.html' },
  { name: 'admin-orders', path: '/admin/orders.html' },
  { name: 'admin-discounts', path: '/admin/discounts.html' },
  { name: 'admin-users', path: '/admin/users.html' },
];

const viewports = [
  { name: 'mobile', width: 390, height: 844 },
  { name: 'desktop', width: 1440, height: 900 },
];

async function freezeClock(page) {
  await page.addInitScript((fixedTime) => {
    const NativeDate = Date;
    class FixedDate extends NativeDate {
      constructor(...args) {
        super(...(args.length ? args : [fixedTime]));
      }

      static now() {
        return fixedTime;
      }
    }

    Object.setPrototypeOf(FixedDate, NativeDate);
    window.Date = FixedDate;
  }, FIXED_TIME);
}

async function stabilizePage(page) {
  await page.addStyleTag({
    content: `
      *, *::before, *::after {
        animation-duration: 0s !important;
        animation-delay: 0s !important;
        transition-duration: 0s !important;
        transition-delay: 0s !important;
        caret-color: transparent !important;
      }
      html { scroll-behavior: auto !important; }
    `,
  });

  await page.evaluate(async () => {
    if (document.fonts?.ready) await document.fonts.ready;
  });
}

async function captureStableScreenshot(page, target, viewport) {
  const response = await page.goto(target.path, { waitUntil: 'networkidle' });
  expect(response?.ok(), `${target.path} should load successfully`).toBeTruthy();
  await stabilizePage(page);

  await page.screenshot({
    path: path.join(outputDirectory, `${target.name}-${viewport.name}.png`),
    fullPage: true,
    animations: 'disabled',
    caret: 'hide',
  });
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

test.beforeAll(() => {
  fs.rmSync(outputDirectory, { recursive: true, force: true });
  fs.mkdirSync(outputDirectory, { recursive: true });
});

for (const viewport of viewports) {
  test.describe.serial(`@visual ${viewport.name} visual regression`, () => {
    test.use({ viewport: { width: viewport.width, height: viewport.height } });

    for (const target of targets) {
      test(`${target.name} captures approved surface`, async ({ page }) => {
        await freezeClock(page);
        await captureStableScreenshot(page, target, viewport);
      });
    }

    test('customer protected surfaces capture approved states', async ({ page }) => {
      await freezeClock(page);
      await loginCustomer(page);

      for (const target of customerTargets) {
        await captureStableScreenshot(page, target, viewport);
      }
    });

    test('admin protected surfaces capture approved states', async ({ page }) => {
      await freezeClock(page);
      await loginAdmin(page);

      for (const target of adminTargets) {
        await captureStableScreenshot(page, target, viewport);
        await expect(page.locator('html')).toHaveAttribute('data-admin-authorized', 'true');
      }
    });
  });
}

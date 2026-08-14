const { test, expect } = require('@playwright/test');
const { waitForOtp, clearOtp, setStock, enterOtp } = require('./helpers');

const INVALID_OTP_MOBILE = '09120000004';
const STOCK_CONFLICT_MOBILE = '09120000005';
const PRODUCT_NAME = 'سرم تست E2E شرفی';
const PRODUCT_SKU = 'E2E-SERUM-001';

function differentOtp(code) {
  const first = (Number(code[0]) + 1) % 10;
  return `${first}${code.slice(1)}`;
}

async function loginFromCheckout(page, mobile) {
  await page.locator('#login-mobile').fill(mobile);
  await page.getByRole('button', { name: 'دریافت کد ورود' }).click();
  const otp = await waitForOtp(mobile);
  await enterOtp(page, otp);
  await page.getByRole('button', { name: 'تأیید و ادامه' }).click();
  await expect(page).toHaveURL(/\/checkout\.html$/);
}

test.describe.serial('Sharafi negative commerce paths', () => {
  test('invalid OTP is rejected without authenticating the browser session', async ({ page }) => {
    await clearOtp(INVALID_OTP_MOBILE);
    await page.goto('/login.html');

    await page.locator('#login-mobile').fill(INVALID_OTP_MOBILE);
    await page.getByRole('button', { name: 'دریافت کد ورود' }).click();
    const otp = await waitForOtp(INVALID_OTP_MOBILE);
    await enterOtp(page, differentOtp(otp));
    await page.getByRole('button', { name: 'تأیید و ادامه' }).click();

    await expect(page.getByText('کد تأیید نامعتبر یا منقضی است.', { exact: true })).toBeVisible();
    await expect(page).toHaveURL(/\/login\.html$/);
    await expect(page.locator('[data-auth-view="otp"]')).toHaveClass(/is-active/);
  });

  test('stock change after checkout quote blocks order creation and keeps user on checkout', async ({ page }) => {
    await setStock(PRODUCT_SKU, 20);
    await clearOtp(STOCK_CONFLICT_MOBILE);

    try {
      await page.goto('/product.html?slug=e2e-test-serum');
      await expect(page.getByRole('heading', { level: 1 })).toHaveText(PRODUCT_NAME);
      await page.locator('.product-purchase .js-product-add').click();

      await page.goto('/cart.html');
      await page.locator('.js-checkout-btn').click();
      await expect(page).toHaveURL(/\/login\.html\?return=/);
      await loginFromCheckout(page, STOCK_CONFLICT_MOBILE);

      await expect(page.locator('.js-checkout-items')).toContainText(PRODUCT_NAME);
      await page.getByLabel('نام و نام خانوادگی').fill('مشتری تعارض موجودی');
      await page.getByLabel('شماره موبایل').fill(STOCK_CONFLICT_MOBILE);
      await page.getByLabel('استان').fill('تهران');
      await page.getByLabel('شهر').fill('تهران');
      await page.getByLabel('آدرس دقیق پستی').fill('خیابان تست تعارض، پلاک ۱۰');
      await page.getByLabel('کد پستی').fill('1234567890');

      await setStock(PRODUCT_SKU, 0);
      await page.getByRole('button', { name: 'ثبت سفارش و ادامه پرداخت' }).click();

      await expect(page.getByText(`موجودی «${PRODUCT_NAME}» کافی نیست.`, { exact: true })).toBeVisible();
      await expect(page).toHaveURL(/\/checkout\.html$/);
      await expect(page.locator('.js-checkout-submit')).toBeEnabled();
    } finally {
      await setStock(PRODUCT_SKU, 20);
    }
  });
});

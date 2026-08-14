const { test, expect } = require('@playwright/test');
const { waitForOtp, clearOtp, setStock, expireOtp, enterOtp } = require('./helpers');

const INVALID_OTP_MOBILE = '09120000004';
const STOCK_CONFLICT_MOBILE = '09120000005';
const CANCEL_ORDER_MOBILE = '09120000006';
const EXPIRED_OTP_MOBILE = '09120000007';
const RESEND_LIMIT_MOBILE = '09120000008';
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

async function fillCheckoutAddress(page, mobile, recipient) {
  await page.getByLabel('نام و نام خانوادگی').fill(recipient);
  await page.getByLabel('شماره موبایل').fill(mobile);
  await page.getByLabel('استان').fill('تهران');
  await page.getByLabel('شهر').fill('تهران');
  await page.getByLabel('آدرس دقیق پستی').fill('خیابان تست، کوچه مرورگر، پلاک ۱۰');
  await page.getByLabel('کد پستی').fill('1234567890');
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

  test('expired OTP is rejected even when the entered code is correct', async ({ page }) => {
    await clearOtp(EXPIRED_OTP_MOBILE);
    await page.goto('/login.html');

    await page.locator('#login-mobile').fill(EXPIRED_OTP_MOBILE);
    await page.getByRole('button', { name: 'دریافت کد ورود' }).click();
    const otp = await waitForOtp(EXPIRED_OTP_MOBILE);
    await expireOtp(EXPIRED_OTP_MOBILE);
    await enterOtp(page, otp);
    await page.getByRole('button', { name: 'تأیید و ادامه' }).click();

    await expect(page.getByText('کد تأیید نامعتبر یا منقضی است.', { exact: true })).toBeVisible();
    await expect(page).toHaveURL(/\/login\.html$/);
    await expect(page.locator('[data-auth-view="otp"]')).toHaveClass(/is-active/);
  });

  test('server resend window is surfaced after a page reload', async ({ page }) => {
    await clearOtp(RESEND_LIMIT_MOBILE);
    await page.goto('/login.html');

    await page.locator('#login-mobile').fill(RESEND_LIMIT_MOBILE);
    await page.getByRole('button', { name: 'دریافت کد ورود' }).click();
    await waitForOtp(RESEND_LIMIT_MOBILE);

    await page.reload();
    await page.locator('#login-mobile').fill(RESEND_LIMIT_MOBILE);
    await page.getByRole('button', { name: 'دریافت کد ورود' }).click();

    await expect(page.getByText('لطفاً کمی بعد دوباره درخواست کد بدهید.', { exact: true })).toBeVisible();
    await expect(page.locator('[data-auth-view="login"]')).toHaveClass(/is-active/);
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
      await fillCheckoutAddress(page, STOCK_CONFLICT_MOBILE, 'مشتری تعارض موجودی');

      await setStock(PRODUCT_SKU, 0);
      await page.getByRole('button', { name: 'ثبت سفارش و ادامه پرداخت' }).click();

      await expect(page.getByText(`موجودی «${PRODUCT_NAME}» کافی نیست.`, { exact: true })).toBeVisible();
      await expect(page).toHaveURL(/\/checkout\.html$/);
      await expect(page.locator('.js-checkout-submit')).toBeEnabled();
    } finally {
      await setStock(PRODUCT_SKU, 20);
    }
  });

  test('customer can cancel pending payment order and reservation becomes sellable again', async ({ page }) => {
    await setStock(PRODUCT_SKU, 1);
    await clearOtp(CANCEL_ORDER_MOBILE);

    try {
      await page.goto('/product.html?slug=e2e-test-serum');
      const addToCart = page.locator('.product-purchase .js-product-add');
      await expect(addToCart).toBeEnabled();
      await addToCart.click();

      await page.goto('/cart.html');
      await page.locator('.js-checkout-btn').click();
      await expect(page).toHaveURL(/\/login\.html\?return=/);
      await loginFromCheckout(page, CANCEL_ORDER_MOBILE);
      await fillCheckoutAddress(page, CANCEL_ORDER_MOBILE, 'مشتری لغو سفارش');

      await page.getByRole('button', { name: 'ثبت سفارش و ادامه پرداخت' }).click();
      await expect(page).toHaveURL(/\/payment-result\.html\?order=SHR-/);
      const orderNumber = new URL(page.url()).searchParams.get('order');
      expect(orderNumber).toMatch(/^SHR-/);

      await page.goto('/product.html?slug=e2e-test-serum');
      await expect(page.locator('.product-purchase .js-product-add')).toBeDisabled();

      await page.goto('/account.html#orders');
      const orderRow = page.locator(`[data-order="${orderNumber}"]`);
      await expect(orderRow).toContainText('در انتظار پرداخت');
      page.once('dialog', (dialog) => dialog.accept());
      await orderRow.getByRole('button', { name: 'لغو سفارش' }).click();

      const cancelledRow = page.locator(`[data-order="${orderNumber}"]`);
      await expect(cancelledRow).toContainText('لغوشده');
      await expect(cancelledRow.getByRole('button', { name: 'ادامه پرداخت' })).toHaveCount(0);
      await expect(cancelledRow.getByRole('button', { name: 'لغو سفارش' })).toHaveCount(0);

      await page.goto('/product.html?slug=e2e-test-serum');
      await expect(page.locator('.product-purchase .js-product-add')).toBeEnabled();
    } finally {
      await setStock(PRODUCT_SKU, 20);
    }
  });
});

const { test, expect } = require('@playwright/test');
const { waitForOtp, clearOtp, settleOrder, enterOtp } = require('./helpers');

const CUSTOMER_MOBILE = '09120000002';
const ADMIN_MOBILE = '09120000003';
const PRODUCT_NAME = 'سرم تست E2E شرفی';

test.describe.serial('Sharafi production commerce flow', () => {
  test('customer completes OTP, server cart, checkout and verified settlement', async ({ page }) => {
    await clearOtp(CUSTOMER_MOBILE);

    await page.goto('/product.html?slug=e2e-test-serum');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(PRODUCT_NAME);
    const primaryAddToCart = page.locator('.product-purchase .js-product-add');
    await expect(primaryAddToCart).toBeEnabled();
    await primaryAddToCart.click();
    await expect(page.locator('.js-cart-count').first()).toHaveText('۱');

    await page.goto('/cart.html');
    await expect(page.getByText(PRODUCT_NAME, { exact: true })).toBeVisible();
    await page.locator('.js-checkout-btn').click();
    await expect(page).toHaveURL(/\/login\.html\?return=/);

    await page.locator('#login-mobile').fill(CUSTOMER_MOBILE);
    await page.getByRole('button', { name: 'دریافت کد ورود' }).click();
    const otp = await waitForOtp(CUSTOMER_MOBILE);
    await enterOtp(page, otp);
    await page.getByRole('button', { name: 'تأیید و ادامه' }).click();
    await expect(page).toHaveURL(/\/checkout\.html$/);

    await expect(page.locator('.js-checkout-items')).toContainText(PRODUCT_NAME);
    await expect(page.locator('.js-checkout-submit')).toBeEnabled();
    await page.getByLabel('نام و نام خانوادگی').fill('مشتری تست مرورگر');
    await page.getByLabel('شماره موبایل').fill(CUSTOMER_MOBILE);
    await page.getByLabel('استان').fill('تهران');
    await page.getByLabel('شهر').fill('تهران');
    await page.getByLabel('آدرس دقیق پستی').fill('خیابان تست، کوچه مرورگر، پلاک ۱۲');
    await page.getByLabel('کد پستی').fill('1234567890');

    await page.getByRole('button', { name: 'ثبت سفارش و ادامه پرداخت' }).click();
    await expect(page).toHaveURL(/\/payment-result\.html\?order=SHR-/);

    const orderNumber = new URL(page.url()).searchParams.get('order');
    expect(orderNumber).toMatch(/^SHR-/);
    await expect(page.locator('.js-payment-order')).toHaveText(orderNumber);
    await expect(page.locator('.js-payment-status')).toHaveText('در انتظار پرداخت');

    await settleOrder(orderNumber);
    await page.reload();
    await expect(page.locator('.js-payment-result-title')).toHaveText('پرداخت با موفقیت تأیید شد ✓');
    await expect(page.locator('.js-payment-status')).toHaveText('پرداخت موفق');

    await page.goto('/account.html#orders');
    await expect(page.locator('#orders')).toContainText(orderNumber);
  });

  test('admin signs in with OTP and loads permission-protected live dashboard', async ({ page }) => {
    await clearOtp(ADMIN_MOBILE);

    await page.goto('/admin/login.html');
    await expect(page.getByRole('heading', { name: 'ورود مدیر' })).toBeVisible();
    await page.locator('#admin-mobile').fill(ADMIN_MOBILE);
    await page.getByRole('button', { name: 'ارسال کد تأیید' }).click();

    const otp = await waitForOtp(ADMIN_MOBILE);
    await page.locator('#admin-code').fill(otp);
    await page.getByRole('button', { name: 'تأیید و ورود' }).click();
    await expect(page).toHaveURL(/\/admin\/dashboard\.html$/);
    await expect(page.locator('html')).toHaveAttribute('data-admin-authorized', 'true');

    const salesKpi = page.locator('[data-kpi="today-sales"] .kpi-value');
    await expect(salesKpi).not.toHaveText('—');
    await expect(page.locator('#dashboardRecentOrders')).toContainText('SHR-');

    await page.goto('/admin/users.html');
    await expect(page.getByText(CUSTOMER_MOBILE, { exact: true })).toBeVisible();
    await expect(page.getByText(ADMIN_MOBILE, { exact: true })).toHaveCount(0);
  });
});

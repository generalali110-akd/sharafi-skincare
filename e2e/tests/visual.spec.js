import { expect, test } from '@playwright/test';

const FIXED_TIME = Date.parse('2026-08-13T08:00:00.000Z');

const targets = [
  { name: 'home', path: '/index.html' },
  { name: 'category', path: '/category.html' },
  { name: 'product', path: '/product.html?slug=e2e-test-serum' },
  { name: 'login', path: '/login.html' },
  { name: 'admin-login', path: '/admin/login.html' },
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

for (const viewport of viewports) {
  test.describe(`${viewport.name} visual regression`, () => {
    test.use({ viewport: { width: viewport.width, height: viewport.height } });

    for (const target of targets) {
      test(`${target.name} matches approved baseline`, async ({ page }) => {
        await freezeClock(page);
        const response = await page.goto(target.path, { waitUntil: 'networkidle' });
        expect(response?.ok(), `${target.path} should load successfully`).toBeTruthy();
        await stabilizePage(page);

        await expect(page).toHaveScreenshot(`${target.name}-${viewport.name}.png`, {
          fullPage: true,
          animations: 'disabled',
          caret: 'hide',
          maxDiffPixelRatio: 0.005,
          threshold: 0.2,
        });
      });
    }
  });
}

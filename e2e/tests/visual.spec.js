import fs from 'node:fs';
import path from 'node:path';
import { expect, test } from '@playwright/test';

const FIXED_TIME = Date.parse('2026-08-13T08:00:00.000Z');
const outputDirectory = path.resolve(process.cwd(), 'test-results', 'visual-current');

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

test.beforeAll(() => {
  fs.rmSync(outputDirectory, { recursive: true, force: true });
  fs.mkdirSync(outputDirectory, { recursive: true });
});

for (const viewport of viewports) {
  test.describe(`@visual ${viewport.name} visual regression`, () => {
    test.use({ viewport: { width: viewport.width, height: viewport.height } });

    for (const target of targets) {
      test(`${target.name} captures approved surface`, async ({ page }) => {
        await freezeClock(page);
        const response = await page.goto(target.path, { waitUntil: 'networkidle' });
        expect(response?.ok(), `${target.path} should load successfully`).toBeTruthy();
        await stabilizePage(page);

        await page.screenshot({
          path: path.join(outputDirectory, `${target.name}-${viewport.name}.png`),
          fullPage: true,
          animations: 'disabled',
          caret: 'hide',
        });
      });
    }
  });
}

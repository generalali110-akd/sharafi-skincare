import fs from 'node:fs';
import path from 'node:path';
import { expect, test } from '@playwright/test';

const frontendRoot = path.resolve(process.cwd(), '..', 'frontend');

function lineNumber(source, index) {
  return source.slice(0, index).split('\n').length;
}

function collectStaticViolations() {
  const violations = [];
  const htmlFiles = [];
  const jsFiles = [];

  function walk(directory) {
    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
      const absolute = path.join(directory, entry.name);
      if (entry.isDirectory()) walk(absolute);
      else if (entry.name.endsWith('.html')) htmlFiles.push(absolute);
      else if (entry.name.endsWith('.js')) jsFiles.push(absolute);
    }
  }

  walk(frontendRoot);

  const htmlRules = [
    { label: 'inline style attribute', pattern: /\sstyle\s*=\s*(["'])/gi },
    { label: 'inline event handler', pattern: /\son[a-z]+\s*=\s*(["'])/gi },
    { label: 'dead hash navigation', pattern: /href\s*=\s*(["'])#\1/gi },
    { label: 'javascript URL', pattern: /(?:href|src)\s*=\s*(["'])\s*javascript:/gi },
    { label: 'inline executable script', pattern: /<script(?![^>]*\bsrc\s*=)(?![^>]*\btype\s*=\s*(["'])(?:application\/ld\+json|application\/json)\1)[^>]*>/gi },
  ];

  for (const file of htmlFiles.sort()) {
    const source = fs.readFileSync(file, 'utf8');
    for (const rule of htmlRules) {
      rule.pattern.lastIndex = 0;
      for (const match of source.matchAll(rule.pattern)) {
        violations.push(`${path.relative(frontendRoot, file)}:${lineNumber(source, match.index)} ${rule.label}`);
      }
    }
  }

  const jsRules = [
    { label: 'runtime inline style mutation', pattern: /\.style(?:\.|\s*=)|\.cssText\s*=|setAttribute\(\s*(["'])style\1/gi },
    { label: 'runtime inline event attribute', pattern: /setAttribute\(\s*(["'])on[a-z]+\1/gi },
  ];

  for (const file of jsFiles.sort()) {
    const source = fs.readFileSync(file, 'utf8');
    for (const rule of jsRules) {
      rule.pattern.lastIndex = 0;
      for (const match of source.matchAll(rule.pattern)) {
        violations.push(`${path.relative(frontendRoot, file)}:${lineNumber(source, match.index)} ${rule.label}`);
      }
    }
  }

  return violations;
}

test('@security storefront source remains strict-CSP compatible', () => {
  const violations = collectStaticViolations();
  expect(violations, violations.join('\n')).toEqual([]);
});

const runtimePages = [
  '/index.html',
  '/category.html',
  '/product.html?slug=e2e-test-serum',
  '/cart.html',
  '/checkout.html',
  '/login.html',
  '/payment-result.html',
  '/admin/login.html',
];

for (const pagePath of runtimePages) {
  test(`@security ${pagePath} does not inject CSP-hostile DOM`, async ({ page }) => {
    await page.goto(pagePath, { waitUntil: 'networkidle' });
    const violations = await page.evaluate(() => {
      const found = [];
      for (const element of document.querySelectorAll('*')) {
        if (element.hasAttribute('style')) found.push(`${element.tagName.toLowerCase()}[style]`);
        for (const attribute of element.attributes) {
          if (/^on/i.test(attribute.name)) found.push(`${element.tagName.toLowerCase()}[${attribute.name}]`);
        }
      }
      for (const link of document.querySelectorAll('a[href="#"]')) found.push(`a[href="#"]:${link.textContent.trim()}`);
      for (const script of document.querySelectorAll('script:not([src])')) {
        const type = (script.getAttribute('type') || '').toLowerCase();
        if (!['application/ld+json', 'application/json'].includes(type)) found.push('script:inline');
      }
      return [...new Set(found)].sort();
    });
    expect(violations, violations.join('\n')).toEqual([]);
  });
}

import fs from 'node:fs';

const reportPaths = process.argv.slice(2);
if (reportPaths.length === 0) {
  console.error('At least one Lighthouse JSON report is required.');
  process.exit(2);
}

const categoryBudgets = {
  performance: 0.8,
  accessibility: 0.9,
  'best-practices': 0.9,
  seo: 0.9,
};

const auditBudgets = {
  'largest-contentful-paint': { max: 3000, unit: 'ms' },
  'cumulative-layout-shift': { max: 0.1, unit: '' },
  'total-blocking-time': { max: 300, unit: 'ms' },
  'total-byte-weight': { max: 1_500_000, unit: 'bytes' },
};

let failed = false;

for (const reportPath of reportPaths) {
  const report = JSON.parse(fs.readFileSync(reportPath, 'utf8'));
  const label = report.finalDisplayedUrl || report.finalUrl || reportPath;
  console.log(`\nLighthouse: ${label}`);

  for (const [categoryId, minimum] of Object.entries(categoryBudgets)) {
    const category = report.categories?.[categoryId];
    const score = category?.score;
    const printable = typeof score === 'number' ? Math.round(score * 100) : 'n/a';
    console.log(`  ${categoryId}: ${printable}`);

    if (typeof score !== 'number' || score < minimum) {
      console.error(`  FAIL ${categoryId}: expected >= ${Math.round(minimum * 100)}`);

      const failedAudits = (category?.auditRefs || [])
        .filter((reference) => reference.weight > 0)
        .map((reference) => report.audits?.[reference.id])
        .filter((audit) => audit && typeof audit.score === 'number' && audit.score < 1)
        .sort((left, right) => left.score - right.score);

      for (const audit of failedAudits) {
        const scorePercent = Math.round(audit.score * 100);
        console.error(`    audit ${audit.id}: ${scorePercent} — ${audit.title}`);
      }

      failed = true;
    }
  }

  for (const [auditId, budget] of Object.entries(auditBudgets)) {
    const value = report.audits?.[auditId]?.numericValue;
    const printable = typeof value === 'number' ? Math.round(value * 100) / 100 : 'n/a';
    console.log(`  ${auditId}: ${printable}${budget.unit}`);

    if (typeof value !== 'number' || value > budget.max) {
      console.error(`  FAIL ${auditId}: expected <= ${budget.max}${budget.unit}`);
      failed = true;
    }
  }
}

if (failed) {
  process.exit(1);
}

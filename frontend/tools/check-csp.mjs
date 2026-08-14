import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

const root = path.resolve(process.cwd(), 'frontend');
const assetsJsRoot = path.join(root, 'assets', 'js') + path.sep;
const violations = [];

function walk(directory) {
  return fs.readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const absolute = path.join(directory, entry.name);
    if (entry.isDirectory()) return walk(absolute);
    return [absolute];
  });
}

function lineAt(source, index) {
  return source.slice(0, index).split('\n').length;
}

function report(file, source, match, message) {
  const relative = path.relative(process.cwd(), file).replaceAll(path.sep, '/');
  violations.push(`${relative}:${lineAt(source, match.index)} ${message}`);
}

function scanHtml(file, source) {
  const rules = [
    [/\sstyle\s*=\s*(["'])/gi, 'inline style attribute'],
    [/\son[a-z][\w:-]*\s*=\s*(["'])/gi, 'inline event handler'],
    [/(?:href|src)\s*=\s*(["'])\s*javascript:/gi, 'javascript: URL'],
    [/href\s*=\s*(["'])#\1/gi, 'dead href="#" navigation'],
  ];

  for (const [pattern, message] of rules) {
    for (const match of source.matchAll(pattern)) report(file, source, match, message);
  }

  const scriptPattern = /<script\b([^>]*)>([\s\S]*?)<\/script>/gi;
  for (const match of source.matchAll(scriptPattern)) {
    const attrs = match[1] || '';
    if (/\bsrc\s*=/.test(attrs)) continue;
    const typeMatch = attrs.match(/\btype\s*=\s*(["'])([^"']+)\1/i);
    const type = (typeMatch?.[2] || '').trim().toLowerCase();
    if (type === 'application/json' || type === 'application/ld+json') continue;
    if ((match[2] || '').trim() !== '') report(file, source, match, 'inline executable script');
  }
}

function scanJs(file, source) {
  const rules = [
    [/<[^>]*\sstyle\s*=\s*(["'])/gi, 'inline style attribute in generated markup'],
    [/<[^>]*\son[a-z][\w:-]*\s*=\s*(["'])/gi, 'inline event handler in generated markup'],
    [/\.style\.[A-Za-z_$][\w$]*\s*=/g, 'runtime inline style mutation'],
    [/\.style\s*\[[^\]]+\]\s*=/g, 'runtime inline style mutation'],
    [/\.style\.setProperty\s*\(/g, 'runtime inline style mutation'],
    [/\.style\.cssText\s*=/g, 'runtime inline style mutation'],
    [/\.cssText\s*=/g, 'runtime inline style mutation'],
    [/setAttribute\(\s*(["'])style\1\s*,/g, 'runtime inline style mutation'],
    [/Object\.assign\([^,]+\.style\s*,/g, 'runtime inline style mutation'],
    [/setAttribute\(\s*(["'])on[a-z][\w:-]*\1\s*,/gi, 'runtime inline event handler'],
    [/\sstyle\s*=\s*(["'])/gi, 'inline style inside generated markup'],
    [/\son[a-z][\w:-]*\s*=\s*(["'])/gi, 'inline event handler inside generated markup'],
    [/\beval\s*\(/g, 'eval is incompatible with strict script-src'],
    [/\bnew\s+Function\s*\(/g, 'Function constructor is incompatible with strict script-src'],
    [/\b(?:setTimeout|setInterval)\s*\(\s*(["'])/g, 'string timer is incompatible with strict script-src'],
  ];

  for (const [pattern, message] of rules) {
    for (const match of source.matchAll(pattern)) report(file, source, match, message);
  }
}

if (!fs.existsSync(root)) {
  console.error('frontend directory not found');
  process.exit(2);
}

for (const file of walk(root).sort()) {
  const ext = path.extname(file).toLowerCase();
  if (ext === '.html') scanHtml(file, fs.readFileSync(file, 'utf8'));
  if (ext === '.js' && file.startsWith(assetsJsRoot)) scanJs(file, fs.readFileSync(file, 'utf8'));
}

if (violations.length) {
  console.error('Strict CSP compatibility violations found:');
  for (const violation of violations) console.error(` - ${violation}`);
  process.exit(1);
}

console.log('Strict CSP compatibility check passed.');

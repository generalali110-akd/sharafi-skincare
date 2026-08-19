import { spawn } from 'node:child_process';
import http from 'node:http';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const e2eRoot = path.resolve(scriptDir, '..');
const repoRoot = path.resolve(e2eRoot, '..');
const port = Number(process.env.FRONTEND_PORT || 4173);
const host = process.env.FRONTEND_HOST || '127.0.0.1';
const baseUrl = `http://${host}:${port}`;

function waitForServer(url, attempts = 40) {
  return new Promise((resolve, reject) => {
    let remaining = attempts;

    const check = () => {
      const request = http.get(url, (response) => {
        response.resume();
        if (response.statusCode && response.statusCode < 500) {
          resolve();
          return;
        }
        retry();
      });

      request.on('error', retry);
      request.setTimeout(1000, () => {
        request.destroy();
        retry();
      });
    };

    const retry = () => {
      remaining -= 1;
      if (remaining <= 0) {
        reject(new Error(`Static server did not become ready at ${url}`));
        return;
      }
      setTimeout(check, 250);
    };

    check();
  });
}

function run(command, args, options = {}) {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, {
      cwd: options.cwd || process.cwd(),
      env: { ...process.env, ...options.env },
      shell: false,
      stdio: options.stdio || 'inherit',
    });

    child.on('error', reject);
    child.on('exit', (code) => {
      if (code === 0) resolve();
      else reject(new Error(`${command} exited with code ${code}`));
    });
  });
}

const node = process.execPath;
const playwrightCli = path.join(e2eRoot, 'node_modules', 'playwright', 'cli.js');

let server = null;

try {
  try {
    await waitForServer(`${baseUrl}/index.html`, 2);
  } catch {
    server = spawn(node, [path.join(repoRoot, 'frontend', 'tools', 'static-server.mjs'), String(port)], {
      cwd: repoRoot,
      env: process.env,
      shell: false,
      stdio: ['ignore', 'pipe', 'pipe'],
    });

    server.stdout.on('data', (chunk) => process.stdout.write(chunk));
    server.stderr.on('data', (chunk) => process.stderr.write(chunk));
    await waitForServer(`${baseUrl}/index.html`);
  }
  await run(node, [playwrightCli, 'test', '--grep', 'responsive QA|strict-CSP|cart flow|storefront API fallback'], {
    cwd: e2eRoot,
    env: { E2E_BASE_URL: baseUrl },
  });
} finally {
  if (server && !server.killed) server.kill();
}

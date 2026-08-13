const crypto = require('node:crypto');
const fs = require('node:fs/promises');
const path = require('node:path');
const { execFile } = require('node:child_process');
const { promisify } = require('node:util');

const execFileAsync = promisify(execFile);
const backendRoot = path.resolve(__dirname, '../../SkinCare');

async function waitForOtp(mobile, timeoutMs = 8_000) {
  const filename = `otp-${crypto.createHash('sha256').update(mobile).digest('hex')}.json`;
  const otpPath = path.join(backendRoot, 'storage/framework/e2e', filename);
  const deadline = Date.now() + timeoutMs;

  while (Date.now() < deadline) {
    try {
      const payload = JSON.parse(await fs.readFile(otpPath, 'utf8'));
      if (/^\d{6}$/.test(String(payload.code || ''))) return String(payload.code);
    } catch (error) {
      if (error.code !== 'ENOENT') throw error;
    }
    await new Promise((resolve) => setTimeout(resolve, 100));
  }

  throw new Error(`OTP file was not created for ${mobile} within ${timeoutMs}ms.`);
}

async function clearOtp(mobile) {
  const filename = `otp-${crypto.createHash('sha256').update(mobile).digest('hex')}.json`;
  const otpPath = path.join(backendRoot, 'storage/framework/e2e', filename);
  await fs.rm(otpPath, { force: true });
}

async function settleOrder(orderNumber) {
  const { stdout, stderr } = await execFileAsync('php', ['artisan', 'e2e:settle-order', orderNumber], {
    cwd: backendRoot,
    env: process.env,
    timeout: 15_000,
  });
  if (stderr.trim()) throw new Error(stderr.trim());
  if (!stdout.includes('paid')) throw new Error(`Unexpected settlement output: ${stdout}`);
}

async function enterOtp(page, code) {
  const inputs = page.locator('[data-auth-view="otp"] .otp-row-v2 input');
  await inputs.first().waitFor({ state: 'visible' });
  for (let index = 0; index < 6; index += 1) {
    await inputs.nth(index).fill(code[index]);
  }
}

module.exports = {
  waitForOtp,
  clearOtp,
  settleOrder,
  enterOtp,
};

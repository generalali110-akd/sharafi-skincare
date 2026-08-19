# CODEX HANDOFF

Generated on 2026-08-19 for:

`G:\Web\Sharafi\Sharafi-Skin-Care`

This file captures the current local/GitHub state for continuing Sharafi Skin
Care work in a fresh Codex session. Do not add secrets to this file.

## Project

- Repository: `generalali110-akd/sharafi-skincare`
- Remote: `https://github.com/generalali110-akd/sharafi-skincare.git`
- Backend: Laravel API in `SkinCare/`
- Frontend: static storefront/admin in `frontend/`
- Browser/static QA: Playwright in `e2e/`
- Deployment and ops: `deploy/`
- CI: `.github/workflows/`

## Current Git State

- Current branch: `codex/release-hardening-final`
- Upstream: `origin/codex/release-hardening-final`
- Current GitHub PR: `#27`, draft, base `main`
- PR URL: `https://github.com/generalali110-akd/sharafi-skincare/pull/27`
- Latest local commit before this handoff update: `ab1dced fix: harden storefront checkout UX`

This branch now carries the release backend hardening work and the storefront
checkout UX/static QA work together, so PR #27 is the combined project update
branch.

## Recent Published Commits

- `bed5f5a fix: complete backend release hardening`
- `1e12d48 fix: stabilize release CI checks`
- `324ddf7 style: satisfy backend pint gate`
- `ab1dced fix: harden storefront checkout UX`

## Local Validation On 2026-08-19

Backend:

- Command: `C:\tmp\sharafi-runtime\php-8.5.9\php.exe artisan test`
- Result: `117 passed, 2 skipped, 571 assertions`

Frontend/static QA:

- Command from `e2e/`: `npm.cmd run test:frontend-static`
- Result: `24 passed`

Other checks:

- `composer validate --strict` passed earlier on the same release branch.
- `git diff --check` passed for the combined frontend commit.
- Targeted Pint passed for `app/Services/Outbox/SmsOutboxDispatcher.php`.

Note: full local `vendor/bin/pint --test` on Windows is noisy because many repo
files differ by line-ending expectations. GitHub's Linux Pint gate was used as
the authoritative formatting signal and was fixed by `324ddf7`.

## Current CI Snapshot

After `324ddf7`, GitHub PR #27 had these checks passing:

- Backend CI
- Secret Scan
- Frontend CI
- CodeQL
- Deployment CI
- Performance CI, one run

Some longer browser/visual/performance duplicate runs were still pending during
the last manual check. Re-check with:

`gh pr checks 27 --watch=false`

## Important Backend Work Included

- Product image API support for admin catalog.
- Product image fields exposed in admin and public catalog resources.
- Cart/catalog image payload behavior without inventory quantity leakage.
- Admin refund completion flow through `PaymentRefundService`.
- Private local storage route boundary via repo-local `config/filesystems.php`.
- Regression coverage for product images, refunds, and storage route safety.
- CI fixes for APP_URL fallback, gitleaks false-positive fingerprint, and Pint.

## Important Frontend Work Included

- Storefront API fallback handling for home/category/product.
- Product card and product detail media/price UX hardening.
- Checkout return and guest cart preservation behavior.
- Auth/mobile overflow and admin product image field support.
- Expanded static QA coverage for responsive and API fallback cases.

## Remaining Release Work

- Let all GitHub PR #27 checks finish and investigate any remaining failures.
- Complete staging smoke with real private staging environment and provider
  credentials.
- Complete `SkinCare/docs/production-readiness-checklist.md`.
- Keep production secrets, `.env.staging`, `.env.production`, database dumps,
  and generated storage artifacts out of Git.

## Runtime Notes

- Known working PHP runtime on this Windows host:
  `C:\tmp\sharafi-runtime\php-8.5.9\php.exe`
- Known Composer PHAR:
  `C:\tmp\sharafi-runtime\composer.phar`
- Use `npm.cmd` from PowerShell for Node scripts.

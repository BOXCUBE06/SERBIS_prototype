# Branch workflow

## `main` — production

**Railway deploys from `update-admin-vue` today, not `main`.** Switching the
Railway service to build from `main` is a pending manual change in the
Railway dashboard, not yet done — until it is, merging into `main` does not
ship by itself. Update this note once that switch happens.

`main` only receives merges from `update-admin-vue`, and only after that
branch's tests pass — backend (`php artisan test`), mobile (`flutter test`),
admin panel (`npm run type-check`). No direct commits to `main`.

`docs/` (this file included) and `audits/` are excluded from `main` —
documentation and audit write-ups live on `update-admin-vue` only. Staging a
merge with `--no-commit --no-ff` and checking `git ls-files docs audits`
before committing is how that stays true; a non-zero result means
`git rm -r --cached docs` (or `audits`) before the merge commit.
`docs-paper/` — the capstone paper, a groupmate's branch — is a different
folder and must never be caught by that exclusion; verify with
`git ls-files docs-paper` on any merge where both are present.

## `update-admin-vue` — where the work happens

Feature work commits directly here unless it is large enough to warrant its
own branch, in which case that branch merges back into `update-admin-vue`
first, and `update-admin-vue` is what eventually merges into `main`.

## OTP bypass — `SERBIS_OTP_BYPASS_CODE`

Test-only, for `/resident/login/verify` (`AuthController::otpBypassMatches()`)
so local development and CI (Playwright) can finish a resident login without
reading the real OTP off SMS or email. Set in a local `.env` only — never on
any deployed environment.

**Confirmed still enforced** (`Backend/SERBIS-Backend/app/Providers/AppServiceProvider.php`):
`boot()` calls `assertOtpBypassIsUnsetInProduction()`, which throws
`RuntimeException('REFUSING TO START: ...')` — the app will not boot — if
`APP_ENV=production` and `config('serbis.otp_bypass_code')` (env
`SERBIS_OTP_BYPASS_CODE`, wired in `config/serbis.php`) is non-empty. Pinned
by `tests/Feature/OtpBypassTest.php`, including
`test_boot_refuses_when_bypass_is_set_in_production` and a request-level
check that the bypass code is rejected even if configured while
`APP_ENV=production`. `.env.example`'s own comment above the var states the
same rule. Both Railway (`docs/deploy-railway.md`) and the still-present
Render config (`render.yaml`) fix `APP_ENV=production`, so the guard's
`app()->environment('production')` check fires on either.

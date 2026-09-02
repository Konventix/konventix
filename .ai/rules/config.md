---
paths:
  - config/octane.php
  - config/app.php
  - config/mail.php
---

# Config

## APP_ENV defaults to `undeclared`, and only the literal `production` is production
`config/app.php` falls back to `undeclared`, not Laravel's `production` (ADR-0053): an instance that never said what it is runs strict, wears the environment ribbon and holds mail back — loud, never quietly production. `Konventix\Platform\Environment\DeploymentEnvironment` is the one reader of that fact; do not add a second notion of "production" (`env('APP_ENV')`, a truthy `SHOW_BANNER` flag) that can disagree with it. Off production every mail passes `GuardNonProductionMail` on the mailer's `MessageSending` event: recipients outside `MAIL_ALLOWLIST` (addresses, `@domain` suffixes, `*`) are dropped and subjects get `[<label>]`. phpunit.xml sets `MAIL_ALLOWLIST=*` and `MAIL_TAG_SUBJECTS=false` so the suite asserts subjects verbatim — a test of the guard itself sets `mail.guard.*` explicitly (`NonProductionMailGuardTest`). A new deployment values file is incomplete without the allowlist; Sail's `.env.example` uses `*` because Mailpit catches everything.

## Octane watch list must cover app-modules, or the dev server serves stale domain code
All domain code lives in `app-modules/` (not `app/`), but Octane's stock `watch` list only covered `app`, `config`, `routes` etc. Result: the dev web role (octane:start --watch) kept executing pre-edit module code from worker memory while fresh-process runs (tests, tinker) used the new code — a fix "passed all tests" yet the browser still reproduced the bug. The watch list now includes `app-modules/*/{src,routes,database}/**/*.php`; keep it in sync if module layout grows. When in doubt whether workers are stale: `sail restart laravel.test` (queue/scheduler/reverb workers hold code in memory too — restart them after backend changes they execute).

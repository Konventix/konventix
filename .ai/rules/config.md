---
paths:
  - config/octane.php
  - config/app.php
  - config/mail.php
  - config/cache.php
---

# Config

## APP_ENV defaults to `undeclared`, and only the literal `production` is production
`config/app.php` falls back to `undeclared`, not Laravel's `production` (ADR-0053): an instance that never said what it is runs strict, wears the environment ribbon and holds mail back — loud, never quietly production. `Konventix\Platform\Environment\DeploymentEnvironment` is the one reader of that fact; do not add a second notion of "production" (`env('APP_ENV')`, a truthy `SHOW_BANNER` flag) that can disagree with it. Off production every mail passes `GuardNonProductionMail` on the mailer's `MessageSending` event: recipients outside `MAIL_ALLOWLIST` (addresses, `@domain` suffixes, `*`) are dropped and subjects get `[<label>]`. phpunit.xml sets `MAIL_ALLOWLIST=*` and `MAIL_TAG_SUBJECTS=false` so the suite asserts subjects verbatim — a test of the guard itself sets `mail.guard.*` explicitly (`NonProductionMailGuardTest`). A new deployment values file is incomplete without the allowlist; Sail's `.env.example` uses `*` because Mailpit catches everything.

## Config keyed by environment name needs a `*` arm — Horizon ran zero supervisors on staging
`config/horizon.php` `environments` had `production` and `local`; Horizon resolves the plan with `Str::is` over those keys, and a miss means a master with NO supervisors — no error, no failed job, `horizon:supervisors` says "No supervisors are running" and every queue (mail on `messaging` included) silently backs up (#160). The `*` arm (last, or it shadows the others) covers `staging`, `testing`, `undeclared` and typos; `HorizonQueueCoverageTest` asserts each of those names resolves a complete plan. Apply the same thinking to any `environment('local')` switch: name the family or ask `isProduction()`.

## Octane watch list must cover app-modules, or the dev server serves stale domain code
All domain code lives in `app-modules/` (not `app/`), but Octane's stock `watch` list only covered `app`, `config`, `routes` etc. Result: the dev web role (octane:start --watch) kept executing pre-edit module code from worker memory while fresh-process runs (tests, tinker) used the new code — a fix "passed all tests" yet the browser still reproduced the bug. The watch list now includes `app-modules/*/{src,routes,database}/**/*.php`; keep it in sync if module layout grows. When in doubt whether workers are stale: `sail restart laravel.test` (queue/scheduler/reverb workers hold code in memory too — restart them after backend changes they execute).

## `cache.serializable_classes` is an allowlist, and a cache hit is the only place it bites
Laravel 13 unserializes every cache hit through `cache.serializable_classes` (config/cache.php); a class not on the list comes back as `__PHP_Incomplete_Class`, and the first method call on it is a PHP `Error`. The skeleton default `false` broke the Pulse dashboard on the dev cluster: Pulse remembers each card's rows for five seconds as a Collection of stdClass rows (CarbonImmutable on the servers and exceptions cards), so roughly every third `wire:poll` — the ones inside the window — answered 500 while the lazy first loads, always a miss, rendered fine. The list now names exactly those three classes; extend it, never flip it to `true`, when something else genuinely has to cache an object. Two things hide this from you: the suite's array store never serializes (the regression test in `ObservabilityDashboardTest` switches it to `serialize => true` and renders each card twice), and on the cluster the app's JSON log lines reach `kubectl logs` only as Octane's `ERROR unknown error.` (its FrankenPHP output parser expects Caddy's `msg` key) — Pulse's own `exception` entries in `pulse_entries` were the evidence.

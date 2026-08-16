---
paths:
  - config/octane.php
---

# Config

## Octane watch list must cover app-modules, or the dev server serves stale domain code
All domain code lives in `app-modules/` (not `app/`), but Octane's stock `watch` list only covered `app`, `config`, `routes` etc. Result: the dev web role (octane:start --watch) kept executing pre-edit module code from worker memory while fresh-process runs (tests, tinker) used the new code — a fix "passed all tests" yet the browser still reproduced the bug. The watch list now includes `app-modules/*/{src,routes,database}/**/*.php`; keep it in sync if module layout grows. When in doubt whether workers are stale: `sail restart laravel.test` (queue/scheduler/reverb workers hold code in memory too — restart them after backend changes they execute).

---
paths:
  - phpstan.neon
  - compose.yaml
  - .env.example
  - README.md
---

# General

## PHPStan needs --memory-limit=1G; tests/ is out of scope
Run it as `composer types:check` — plain `phpstan analyse` dies at PHP's default 128M with a misleading "Unable to load dynamic library phpstan_turbo" warning. The turbo extension is a red herring; the memory limit is the actual cause.

`tests/` is deliberately not in `paths`. Pest's higher-order `TestCall` chain is invisible to PHPStan without `pestphp/pest-plugin-phpstan`, so scanning tests yields only false positives. Add the plugin first if you want the coverage.

## `.env` must exist before the first `sail up`, or the pgsql volume is poisoned
compose.yaml passes `${DB_USERNAME}`/`${DB_DATABASE}` to the pgsql service, and Postgres only honours `POSTGRES_USER`/`POSTGRES_DB` during first-time `initdb`. Bring the stack up with no `.env` and they interpolate to empty strings: the cluster initializes as the default `postgres` role, and every later boot logs `Skipping initialization` and ignores the now-correct `.env` permanently. The symptom is `SQLSTATE[08006] … FATAL: password authentication failed … Role "konventix" does not exist` on `sail artisan migrate` — an authentication failure wearing a connection error's clothing, because 08006 is the connection-exception SQLSTATE class. Do not debug the network or `DB_HOST`; check `docker logs <pgsql> | head -1` for the skip line, and confirm with `psql -U postgres -l` that the role is absent. The only fix is re-running `initdb`: `docker compose down && docker volume rm konventix-poc_sail-pgsql && sail up -d`. Recreating the volume also re-runs `create-testing-database.sql`, which matters because a `testing` database left owned by `postgres` instead of the app role breaks the suite on table creation. Note this ordering trap is not obvious from `composer setup`: that script is what *creates* `.env`, so it cannot be the first thing you run on Docker.

## Dev stack mirrors production roles: one image, one container per role
compose.yaml runs each deployment role (web, queue, scheduler, reverb) as a separate service from the same Sail image via command override — mirroring ADR-0002/ADR-0025 production topology. Never mount docker.sock or spawn containers from the application; that is root-equivalent host access. Reverb is ONE shared server (browser: localhost:${FORWARD_REVERB_PORT}; backend: http://reverb:8080); brands become app entries in config/reverb.php, not extra processes. Host ports 8080–8083 clash with other local stacks on this machine — FORWARD_REVERB_PORT dodges that.

## A one-shot migrator role gates stack startup; AUTO_MIGRATE=false skips it
Fresh `sail up` used to crash-loop queue/scheduler/reverb with `relation "cache" does not exist` because those roles raced an unmigrated database. compose.yaml now runs a one-shot `migrate` service (Kubernetes-Job style) that every application role waits on via `depends_on: condition: service_completed_successfully`; pgsql/redis are gated on `service_healthy`. The service runs `php artisan konventix:migrate` — the gate lives in that command (`config('database.auto_migrate')` ← `AUTO_MIGRATE` env), inside the application image, so any deployment target (compose, a future K8s Job) reuses it identically. Operators disable it with `AUTO_MIGRATE=false` in `.env` — the command then exits 0 without touching the database, and migrations must be run manually (`sail artisan migrate`). Read the flag via config, never `env()` in the command, or `config:cache` in production silently breaks the gate.

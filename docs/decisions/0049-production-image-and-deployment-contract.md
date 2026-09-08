# ADR-0049: One production image, and the contract a deployment holds us to

**Status:** accepted

## Context

The Helm chart for Konventix will live in its own repository, and its
requirements document (`docs/helm-chart-requirements.md`, written against
commit `6b2da0b`) opens with a list of things this repository must deliver
before that chart can produce a working instance. The list is accurate:
there is no production container image — the Sail runtime bind-mounts the
source, runs supervisord and lets Octane download FrankenPHP at first
start; the browser's Reverb endpoint is baked into the JS bundle at build;
Inertia SSR is hardcoded on with no SSR process anywhere; a freshly
migrated database answers 404 on every host and has nobody who can log in;
and Horizon supervises `default` and `waiting-room` while Messaging
dispatches to a `messaging` queue no worker ever reads — under the
production topology, queued mail is silently never sent.

The deployment ambition is staging first, promotable to production: the
same image and the same contract serve both, and promotion is
configuration.

## Decision

1. **One multi-stage Dockerfile, in this repository, published to GHCR by
   CI.** The base is the official `dunglas/frankenphp` image on PHP 8.5 —
   FrankenPHP is baked in, not downloaded at first start. Build stages:
   composer (`--no-dev --optimize-autoloader`, so Telescope and all dev
   tooling are absent), assets (`php artisan wayfinder:generate
   --with-form` against a throwaway key, then `npm ci && npm run build`
   and the SSR bundle), and a runtime stage without Node. The runtime
   carries `pdo_pgsql pgsql redis intl bcmath gmp gd zip pcntl sockets
   opcache` (the rest of the required list ships with the base image),
   `postgresql-client-17` for `pg_dump`, a non-root `konventix` user, an
   unprivileged listen port (8000), the `public/storage` symlink created
   at build, and `XDG_CONFIG_HOME`/`XDG_DATA_HOME` pointed at `/tmp` so
   Caddy's autosave never needs the image to be writable. `APP_VERSION`
   and `SENTRY_RELEASE` enter as build args from the tag or commit.
   GitHub Actions builds on pushes to `main` and on `v*` tags and pushes
   immutable tags (`sha-<short>`, semver on tags) to
   `ghcr.io/konventix/konventix`; the workflow boots the built image against
   throwaway Postgres/Redis and asserts `/up` answers 200 before pushing.

   *Amended 2026-09-02 (#59, #121): the pipeline is `.gitlab-ci.yml` and the
   registry is `registry.sxcs.de/konventix/konventix`. The contract is
   unchanged — immutable tags only, `sha-<short>` on every published build
   and the semver name added to that same digest on a tag, and nothing
   published from a commit whose tests are red. What changed is where: builds
   happen on pushes to `develop` and `main` and on any tag, so a tag on
   `develop` publishes a release candidate the same way a tag on `main`
   publishes a release. The GitHub workflow is left in place and still names
   GHCR; it fires only on a push to the archived remote.*

   *Amended 2026-09-08 (#255): the GitHub workflow is deleted. It had no
   `needs` on the test workflow and none of the scanners, so it was a second
   publishing path around every bar `scan:gate` holds up, for an image no
   consumer pulls — the chart pins `registry.sxcs.de/konventix/konventix`.
   GitLab is the only path to a published image.*

   *Amended 2026-09-08 (#254): the runtime stage copies `docker/php.ini` to
   `/usr/local/etc/php/conf.d/99-production.ini`. The base image activates
   neither of its own, so without it the artifact ran on PHP's compiled-in
   defaults — `display_errors=STDOUT`, `log_errors=Off`, `expose_php=On`,
   `memory_limit=128M`, `opcache.validate_timestamps=On`. The `toolchain`
   stage is deliberately not covered, so CI keeps its own `99-ci.ini`.*

2. **Laravel's caches are built at container start, never at image
   build.** `config:cache` freezes the environment it sees, and the
   environment differs per deployment — so an entrypoint runs
   `config:cache route:cache view:cache event:cache` and then `exec "$@"`,
   leaving the role command as PID 1 to receive SIGTERM.
   `bootstrap/cache` is therefore a writable mount (emptyDir in the
   chart), not image state.

3. **`konventix:migrate` also syncs permissions.** Platform's TODO says
   `permissions:sync` must run on every deploy and nothing automates it;
   folding it into the migrate command — after the migration step, behind
   the same `AUTO_MIGRATE` gate, read via config never `env()` — means
   the chart's pre-upgrade Job stays one command, and the dev compose
   stack gets it for free.

4. **`konventix:install` bootstraps a fresh instance without a
   terminal.** Idempotent and flag/env-driven: it creates the operator
   with its admin host, the first operator-admin user (password from an
   environment variable, never a flag), and optionally a first brand with
   its domain — and exits 0 without changes when they already exist. The
   chart runs it as a post-install hook; `DatabaseSeeder` and
   `konventix:seed` stay what they are, development fixtures.

5. **The browser derives its websocket endpoint from the page it is
   on.** `wsHost` is `window.location.hostname`, the port is the page's
   port, `forceTLS` follows the page's scheme, and the path is fixed:
   `/ws`, matched by `REVERB_SERVER_PATH` on the server. ADR-0025 already
   states the target — `wss://<brand-domain>/ws` on every brand host,
   forwarded by the proxy to the one Reverb backend — and one image can
   now serve any deployment. What the ingress owes: route the websocket
   prefix (`/ws`, i.e. the Pusher path `/ws/app/{key}`) on **every brand
   host and the admin host** to the Reverb service with long idle
   timeouts; the backend HTTP API (`/apps/{appId}/events`) is reached
   only cluster-internally via `REVERB_HOST` and is never exposed. Dev
   keeps working because the dev values resolve to the same host and
   port they always did.

6. **Inertia SSR is a deployment switch.** `INERTIA_SSR_ENABLED` and
   `INERTIA_SSR_URL` in `config/inertia.php`; defaults keep today's dev
   behavior. The first release deploys with SSR off (D1 of the chart
   requirements); the image ships the SSR bundle so a deployment that
   wants the `ssr` role turns it on without a rebuild.

7. **Every queue a job names has a Horizon supervisor, and a test
   enforces it.** The queue list is `default`, `messaging`,
   `waiting-room` — each its own supervisor so a rush never starves mail
   and mail never starves the rush. Timeouts are declared per supervisor
   and the largest one is the number the chart reads for
   `terminationGracePeriodSeconds`. The guard is a test that scans the
   codebase for `onQueue(...)` literals and fails when one names a queue
   no Horizon supervisor works — the test that would have caught the
   `messaging` gap the day it was created.

8. **Backups are switchable.** `BACKUP_ENABLED=false` skips the
   schedule entirely (CloudNativePG owns database backups in the
   cluster); `BACKUP_INCLUDE_DATABASE=false` keeps spatie/laravel-backup
   for `storage/` files only. Defaults keep today's behavior for dev and
   any single-host deployment.

9. **Migrations are expand/contract.** The chart migrates in a
   pre-upgrade Job while the previous release still serves traffic, so
   every migration must be backward-compatible for one release: new
   columns nullable or defaulted, renames staged over two releases,
   destructive drops one release after the code stopped reading the
   column. The maintenance-mode toggle (`APP_MAINTENANCE_DRIVER=cache`,
   store `redis`) stays available in chart values as the escape hatch
   for a migration that cannot be shaped that way — using it is the
   exception and the PR that needs it must say so.

10. **PHP is pinned `^8.5`.** `composer.json` said `^8.3` while CI, Sail
    and `config/database.php` (`Pdo\Mysql`, PHP 8.4+) already assumed
    newer; the lockfile, CI and the image now agree.

11. **The disk-space health check watches a volume that matters.**
    `UsedDiskSpaceCheck` measures the container's root filesystem —
    ephemeral and shared with the node. It becomes env-configurable:
    skippable, and pointable at the media volume where local disks are
    in use.

12. **`APP_KEY` custody is the deployment's, and rotation is
    documented.** Brand mail transports (ADR-0028), PSP keys (ADR-0046)
    and per-brand Reverb secrets are encrypted with it; it is generated
    once, lives in the deployment's secret store, and must be stable for
    the life of the database. Rotation goes through `APP_PREVIOUS_KEYS`
    (decrypt with old, encrypt with new) and is written down in
    `.env.example` beside the key.

## Alternatives rejected

- **The Sail image in production**: bind-mounted source, supervisord as
  PID 1, FrankenPHP downloaded at first start, dev tooling and Telescope
  present — development ergonomics, not a deployment artifact.
- **Baking `config:cache` into the image**: freezes one environment into
  an artifact meant to serve many; staging-promotable-to-production is
  exactly the case it breaks.
- **Publishing to the private GitLab registry**: the repository and its
  CI live on GitHub; `GITHUB_TOKEN` pushes to GHCR with no new
  credentials, and the chart pulls with one imagePullSecret. A mirror can
  be added later without touching this decision.
- **A maintenance window around every deploy**: a brief outage per
  release is the wrong default for a platform whose shop has a waiting
  room; expand/contract costs discipline, not availability.
- **Reverb endpoint via runtime-injected `window.__reverb` config**: a
  second channel for what the page already knows; `window.location` is
  authoritative and cannot drift from the host the visitor is actually on.

## Consequences

- The chart repository is unblocked: B1–B12 of its requirements document
  are delivered or explicitly answered (B6 standalone Redis and B11
  object storage need no code — `storage:doctor` already runs
  non-interactively and the S3 path was exercised against Garage in
  slice 17).
- Queued mail under the production topology goes from silently dropped
  to delivered — and dev compose gains the `messaging` supervisor too,
  so dev and production work the same queues.
- The owed k6 rush run (ROADMAP slice 24) becomes possible: the image
  behind a real load balancer is the "later infrastructure task" the
  load-test concern named.
- Every future migration author carries the expand/contract obligation;
  review must check it.
- The image build needs network access for the Bunny fonts plugin during
  `npm run build` — a hermetic build is not currently possible and is
  accepted.

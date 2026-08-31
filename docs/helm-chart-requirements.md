# Konventix Helm chart — requirements

Status: draft, 2026-08-28. Written from a read-through of the `konventix`
repository at commit `6b2da0b` (compose.yaml, `.env.example`, `config/`,
`bootstrap/app.php`, ADR-0002/0025/0028/0029/0043/0047, `docs/architecture/
overview.md`, the module providers and the observability provider).

Two audiences:

- **The konventix implementer** — section 1 is the list of things the
  application repository has to deliver before a chart can be installed and
  produce a working instance. Items are ordered by how hard they block.
- **The chart author** — sections 2–4 record the runtime contract the chart
  will implement, the external services it assumes, and the decisions still
  open.

## 0. Scope decided for this chart

- The chart deploys **application roles only**. PostgreSQL (CloudNativePG),
  Redis, object storage, SMTP, OpenBao, the External Secrets Operator, the
  ingress controller and cert-manager are **outside** the chart and referenced
  by name.
- The chart **never renders secret values from `values.yaml`**. Every secret
  is an existing Kubernetes `Secret` (created by ESO from OpenBao, or by CNPG
  for the database) that the chart references by name. Optionally the chart
  renders `ExternalSecret` objects pointing at OpenBao KV paths.
- One application image, one Deployment/Job per role via command override —
  exactly the topology `compose.yaml` already mirrors (ADR-0002, ADR-0025).

## 1. What konventix must deliver first

### B1 — A production container image (blocker)

There is no Dockerfile. The Sail runtime is development-only: it bind-mounts
the source, runs supervisord, and lets Octane **download the FrankenPHP
binary at first start** (the 167 MB gitignored `frankenphp` file in the repo
root is that download). Nothing of this is usable in a cluster.

The image must:

- Be **multi-stage**: composer `--no-dev --optimize-autoloader` (Telescope is
  `require-dev` and must be absent), `php artisan wayfinder:generate
  --with-form` (its output is gitignored), `npm ci && npm run build`
  (`build:ssr` too if B3 says yes — the Vite build needs network for the
  Bunny fonts plugin), then a runtime stage without Node and without dev
  tooling.
- Ship **FrankenPHP baked in** (`dunglas/frankenphp` base or the static
  binary), PHP **8.5** (see B14), and these extensions: `pdo_pgsql pgsql
  redis intl bcmath gmp gd zip sodium pcntl posix sockets mbstring xml curl
  fileinfo zlib opcache`. Add the `postgresql-client` (`pg_dump`, same major as
  the server) while `backup:run` includes the database (B10).
- Run as a **non-root user** and listen on an unprivileged port (chart default
  `8000`); no `setcap`, no port 80.
- Have an **entrypoint** that runs `php artisan config:cache route:cache
  view:cache event:cache` **at container start**, not at build. `config:cache`
  freezes the environment it sees, and the environment differs per
  deployment. Then `exec "$@"` so the role command is PID 1 and receives
  SIGTERM. `bootstrap/cache` therefore has to be writable at runtime (writable
  layer, or an emptyDir the entrypoint seeds with the build-time
  `packages.php`/`services.php`).
- Point `XDG_CONFIG_HOME` / `XDG_DATA_HOME` at a writable tmp path (the chart
  mounts an emptyDir). compose.yaml points them at `config/` and `data/` in the
  repo, which is why `config/caddy` and `data/caddy` litter exists — in a
  read-only image FrankenPHP/Caddy would fail to write its autosave and
  instance id.
- Create the `public/storage` symlink at build (`storage:link`) — it is
  gitignored and load-bearing for local media disks (ADR-0043).
- Accept `APP_VERSION` / `SENTRY_RELEASE` as a build arg from the tag or
  commit (the footer and Sentry both read it).
- Be published to a registry with immutable tags; the chart's `image.tag`
  defaults to `appVersion`.

Where the Dockerfile lives is decision D7; the recommendation is the
konventix repository, next to `compose.yaml`, built by its CI.

**Delivered** (ADR-0049 §1): `Dockerfile`, `docker/entrypoint.sh` and
`.dockerignore`, built and smoked by `.github/workflows/image.yml` on every
push to `main` and on `v*` tags, published to `ghcr.io/konventix/konventix`.

### B2 — Runtime-configurable browser endpoint for Reverb (blocker)

`resources/js/app.ts` calls `configureEcho({ broadcaster: 'reverb', key })`.
Only the **key** comes from a shared Inertia prop per brand; host, port and
scheme fall back to `import.meta.env.VITE_REVERB_HOST/PORT/SCHEME`, which are
**baked into the JS bundle at image build**. A chart cannot set them, and one
image cannot serve two deployments.

ADR-0025 already states the target: the browser connects to
`wss://<brand-domain>/<path>` on the brand's own host and the proxy forwards
to the one Reverb backend. Deliver:

- `wsHost` derived from `window.location.hostname` (or a shared prop next to
  `reverb.key`), `wssPort`/`wsPort` from the page's port, `forceTLS` from the
  page's scheme, and a fixed `wsPath` (e.g. `/ws`) matched by
  `REVERB_SERVER_PATH` on the server.
- A written statement of which paths the ingress must route to the Reverb
  Service: the Pusher-protocol websocket path (`/app/{key}`, under the prefix
  above) on **every brand host**, and confirmation that the backend HTTP API
  (`/apps/{appId}/events`) is reached only cluster-internally via
  `REVERB_HOST`.

Server-side nothing changes: `REVERB_HOST/PORT/SCHEME` are the backend's
address of the Reverb Service (`http://konventix-reverb:8080`), and the
`brands` application provider reads brand rows from Postgres, so the reverb
role needs database access too.

**Delivered** (ADR-0049 §5): `resources/js/lib/reverbEndpoint.ts` derives
`wsHost`/port/`forceTLS` from `window.location` with a fixed `/ws` path, and
`resources/js/app.ts` now calls it instead of reading the Vite-baked
`VITE_REVERB_HOST/PORT/SCHEME` env vars.

### B3 — Inertia SSR: decide, and make it configurable (blocker if SSR stays on)

`config/inertia.php` hardcodes `ssr.enabled = true` and
`url = http://127.0.0.1:13714`. There is no SSR bundle, no Node runtime, and
no SSR process in compose. Under Octane every request would first try the SSR
gateway and fall back to client rendering.

Deliver `INERTIA_SSR_ENABLED` and `INERTIA_SSR_URL` env switches, and decide
D1. If SSR is a release feature: `npm run build:ssr` in the image, a Node
runtime (in the image or a second image), and the chart adds an `ssr` role
(`node bootstrap/ssr/ssr.mjs` or `php artisan inertia:start-ssr`) that the
web role reaches over a Service.

**Delivered** (ADR-0049 §6): `INERTIA_SSR_ENABLED`/`INERTIA_SSR_URL` in
`config/inertia.php`, defaulting to today's dev behaviour (`true`,
`http://127.0.0.1:13714`). D1 decided: the chart sets
`INERTIA_SSR_ENABLED=false` for the first release; the image still builds the
SSR bundle so a deployment can turn on the `ssr` role without a rebuild.

### B4 — Non-interactive first-run bootstrap (blocker)

Every web request resolves a brand from the `Host` header or 404s
(ADR-0001); the admin console lives on `operators.admin_host`, also a
database row (ADR-0029). A freshly migrated database has **no operator, no
admin host, no brand and no user**. Today those rows come from
`DatabaseSeeder` (a hard-coded "Konventix Dev Operator") or `konventix:seed`
(interactive demo data). A chart-installed instance therefore answers 404 on
every host and has nobody who can log in.

Deliver an idempotent, flag/env-driven command — e.g. `konventix:install
--operator-name= --admin-host= --admin-email= --admin-password-from-env=
[--brand-name= --brand-domain=]` — that creates the operator with its admin
host, the first operator-admin user (with the seeded "Operator Admin" role),
and optionally a first brand with its domains, and exits 0 without changes
when they already exist. The chart runs it as a post-install hook.

Related: `app-modules/platform/TODO.md` says **`permissions:sync` must run in
every deploy** and nothing automates it. Either fold it into
`konventix:migrate` (after the migration step, still behind `AUTO_MIGRATE`) or
confirm the chart may run `konventix:migrate && permissions:sync` in one Job.

**Delivered** (ADR-0049 §3–§4): `app/Console/Commands/InstallCommand.php`
(`konventix:install`), idempotent and env/flag-driven, run by the chart as a
post-install hook. `konventix:migrate`
(`app/Console/Commands/MigrateOnStartCommand.php`) now runs
`permissions:sync` after migrating, behind the same `AUTO_MIGRATE` gate, so
the chart's pre-upgrade Job stays one command.

### B5 — Horizon does not work the `messaging` queue (blocker for mail)

`Konventix\Messaging\Jobs\SendQueuedMessage` dispatches `onQueue('messaging')`.
`config/horizon.php` defines supervisors for `['default']` and
`['waiting-room']` only. Under Horizon (the only worker the compose and
production topology run) queued mail is never sent. Add a `messaging`
supervisor — and, since `docs/architecture/overview.md` promises "queue
workers split by queue name (long-running provisioning isolated with extended
timeout)", define the full queue list and per-queue timeouts now; the chart
sizes `terminationGracePeriodSeconds` from the largest timeout.

**Delivered** (ADR-0049 §7): a `messaging` supervisor added to
`config/horizon.php` beside `default` and `waiting-room`, each with its own
declared timeout; `tests/Feature/HorizonQueueCoverageTest.php` scans the
codebase for `onQueue(...)` literals and fails when one names a queue no
Horizon supervisor works.

### B6 — Redis topology: three logical databases (decision D2)

Sessions/queue use DB 0, cache DB 1, the waiting room DB 2
(`REDIS_DB`, `REDIS_CACHE_DB`, `REDIS_WAITING_ROOM_DB`). Numbered databases
exist only on a **standalone** Redis: Redis Cluster supports DB 0 only, and
Sentinel-based failover is not configured in `config/database.php` (no
`sentinel` options). The chart will therefore assume a single-endpoint Redis
(operator-managed primary/replica is fine as long as the client sees one
address). If HA via Cluster is wanted later, the split has to move to key
prefixes on one database. Also required of the external Redis (ADR-0047 point
6, compose.yaml): `maxmemory-policy noeviction`, `appendonly yes`, AUTH.

**Answered, no code** (ADR-0049 Consequences): the chart assumes a
single-endpoint standalone Redis and the numbered-database split stays, as
this document's own D2 recommends; the external-Redis requirements
(`noeviction`, AOF, AUTH) stand as section 3 states them.

### B7 — Backups: make them switchable (decision D4)

`ObservabilityServiceProvider` schedules `backup:run` / `backup:clean`
unconditionally, and `config/backup.php` always includes the database
(`env('DB_CONNECTION')`). On Kubernetes with CloudNativePG, database backups
are CNPG's job (Barman / volume snapshots). Deliver `BACKUP_ENABLED` (skips the
schedule) and `BACKUP_INCLUDE_DATABASE` (files only), so the chart can run
spatie/laravel-backup for `storage/` alone or not at all. If it stays on, the
image needs `pg_dump` (B1) and `BACKUP_DESTINATION_DISK` must name a disk that
is **not** the application's own bucket.

**Delivered** (ADR-0049 §8): `BACKUP_ENABLED` gates `config/backup.php`'s
`schedule_enabled`, read by `ObservabilityServiceProvider` before scheduling
`backup:clean`/`backup:run`; `BACKUP_INCLUDE_DATABASE` gates the `databases`
entry in the backup config. Defaults keep today's behaviour.

### B8 — Migration/rollout policy (decision D8)

The chart runs migrations in a Helm `pre-upgrade` Job while the previous
release's pods still serve traffic. Either the repository commits to
**expand/contract-compatible migrations** (new columns nullable or defaulted,
drops one release later), or the chart puts the app in maintenance mode around
the Job (`APP_MAINTENANCE_DRIVER=cache`, store `redis`, so `artisan down` is
cluster-wide — both are env-driven already). State the policy in the repo.

**Delivered** (ADR-0049 §9): the repository commits to expand/contract —
new columns nullable or defaulted, renames staged over two releases,
destructive drops one release after the code stops reading the column — with
the maintenance-mode toggle kept as the documented escape hatch for a
migration that cannot be shaped that way.

### B9 — Health check semantics in a cluster (minor)

Good news first: `/up` is registered outside the `web` group, so it survives
any `Host` and touches no database — usable as readiness/liveness for the web
role. `/health-json` is guarded by `X-Health-Secret` (404 when the secret is
unset) and host-agnostic — usable by an external monitor. Reverb ships `/up`
on its own port; when `REVERB_SERVER_PATH` is set the whole route set moves
under that prefix, so the probe path becomes `<path>/up` (`/ws/up` for the
`/ws` this document recommends). Horizon has `horizon:status`.

Two checks need a look: `UsedDiskSpaceCheck` measures the container's root
filesystem, which is ephemeral and shared with the node; point it at the
media volume when local disks are used, or make it skippable. `HealthCheck`
notifications go to `HEALTH_TO_ADDRESS` via the fallback mailer — fine, just
document it.

**Delivered** (ADR-0049 §11): `HEALTH_DISK_CHECK` (skip the check) and
`HEALTH_DISK_CHECK_PATH` (point it at a volume that matters) in
`config/health.php`.

### B10 — Version alignment (minor)

`composer.json` requires `php ^8.3`; CI and Sail run 8.5; `config/database.php`
imports `Pdo\Mysql`, which exists only from PHP 8.4. Pin `"php": "^8.5"` (or
`^8.4`) so the lockfile, CI and the image agree.

**Delivered** (ADR-0049 §10): `composer.json` pins `"php": "^8.5"`; the
lockfile, CI and the image now agree.

### B11 — Object storage is the cluster default (no code, but confirm)

Local disks put media, invoices and exports under `storage/app/…`, written by
**web and queue** pods (variants and PDFs are generated in jobs) and read by
web through the `public/storage` symlink. In Kubernetes that means one
ReadWriteMany volume mounted in every role — possible, but the chart's default
will be `MEDIA_DISK=media_s3`, `INVOICES_DISK=invoices_s3`,
`EXPORTS_DISK=exports_s3`. Confirm the S3 path has been exercised against
Hetzner or Garage with one endpoint and path-style addressing, and that
`storage:doctor` can run non-interactively as a Job (the chart will offer it
as a `helm test`).

**Answered, no code** (ADR-0049 Consequences): needs no code — `storage:doctor`
already runs non-interactively and the S3 path was exercised against Garage
in slice 17.

### B12 — `APP_KEY` custody (documentation)

Brand mail transports (ADR-0028), PSP keys (ADR-0046) and per-brand Reverb
secrets are encrypted at rest with `APP_KEY`. The chart never generates it;
it comes from OpenBao and must be stable for the life of the database.
Document the rotation procedure via `APP_PREVIOUS_KEYS` in the repo.

**Delivered** (ADR-0049 §12): `.env.example` documents `APP_KEY` custody —
generated once, held in the deployment's secret store, stable for the life
of the database — and the `APP_PREVIOUS_KEYS` rotation procedure, beside the
key itself.

### Already right — no change needed

- Every `env()` call sits in `config/` (`trustedproxy.php`, `health.php`,
  `database.auto_migrate`), so `config:cache` is safe.
- `konventix:migrate` with the `AUTO_MIGRATE` gate is exactly the Job the
  chart needs.
- `LOG_CHANNEL=stderr` exists (`LOG_STDERR_FORMATTER` for JSON); the chart
  sets it — the default `single` channel would write `storage/logs/konventix.log`.
- `TRUSTED_PROXIES` is config-driven; `X-Forwarded-Host/Proto` are trusted.
- Reverb apps come from brand rows (`REVERB_APP_PROVIDER=brands`); the
  `REVERB_APP_*` fallback credentials can stay empty.
- `APP_MAINTENANCE_DRIVER`/`_STORE` are env-driven.
- `/caddy/ask` exists for a proxy that wants on-demand TLS.

## 2. Runtime contract the chart implements

### Roles

| Role | Command | Replicas | Probes | Needs |
| --- | --- | --- | --- | --- |
| `migrate` (Job, `pre-install`/`pre-upgrade` hook) | `php artisan konventix:migrate` then `permissions:sync` | 1, `backoffLimit` small | — | Postgres |
| `install` (Job, `post-install` hook, optional) | `konventix:install …` (B4) | 1 | — | Postgres, Redis |
| `web` | `php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=8000 --workers=N --max-requests=M` | 2+, HPA on CPU | HTTP `/up` on 8000 | Postgres, Redis, storage |
| `horizon` | `php artisan horizon` | 1+ | exec `php artisan horizon:status` | Postgres, Redis, storage |
| `scheduler` | `php artisan schedule:work` | **exactly 1**, `Recreate` | process | Postgres, Redis, storage (backup temp) |
| `pulse` | `php artisan pulse:work` | 1 | process | Postgres, Redis |
| `reverb` | `php artisan reverb:start --host=0.0.0.0 --port=8080` | 1 (n with `REVERB_SCALING_ENABLED=true`) | HTTP `/up` on 8080 | Postgres, Redis |
| `ssr` (optional, B3) | `node bootstrap/ssr/ssr.mjs` | 1+ | TCP 13714 | — |

All roles: `terminationGracePeriodSeconds` ≥ the longest queue timeout
(Horizon) / Octane request timeout; `preStop` not needed — Horizon and
Octane handle SIGTERM. Scheduled work (every minute: `commerce:waiting-room-tick`,
`messaging:flush-held`, `health:schedule-check-heartbeat`; every 5/15 minutes:
`horizon:snapshot`, `health:check`, `commerce:expire-carts`,
`registration:expire-waitlist-offers`; daily: dunning, retention, backups,
settlement refresh) runs only in `scheduler`.

### Network

- **Ingress hosts**: every brand domain **plus** the operator admin host.
  Brands are database rows added at runtime, so the host list is either
  maintained in values (with cert-manager per host), a wildcard, or an
  on-demand-TLS proxy using `/caddy/ask` — decision D3.
- The proxy must forward `Host` unmodified and set `X-Forwarded-Proto`,
  `X-Forwarded-Host`, `X-Forwarded-For`; `TRUSTED_PROXIES` is set explicitly
  from values (pod/ingress CIDRs, or `*` when a NetworkPolicy makes the web
  Service reachable only from the ingress controller).
- Websocket path (B2) on every host → `reverb` Service; long idle timeouts
  (`proxy-read-timeout` ≥ 3600 s).
- Upload size: media accepts 10 MB → `proxy-body-size` ≥ 12m.
- Internal Services: `web:8000`, `reverb:8080`, `ssr:13714`.
- `/up` and `/health-json` are not exposed publicly by default; a
  `ServiceMonitor` is not possible — there is **no Prometheus/OpenTelemetry
  endpoint** in the application (Pulse and Horizon are UI-only). Worth a
  future item.

### Volumes

| Mount | Kind | Roles |
| --- | --- | --- |
| `storage/framework`, `storage/logs`, `/tmp`, XDG dirs, `bootstrap/cache` (see B1) | emptyDir | all |
| `storage/app/backup-temp` | emptyDir | scheduler |
| `storage/app` (local-disk mode only) | PVC **ReadWriteMany** | web, horizon, scheduler |

Sessions and cache live in Redis; nothing else is stateful in a pod.

### Environment (ConfigMap, non-secret)

`APP_NAME APP_ENV=production APP_DEBUG=false APP_URL APP_VERSION APP_LOCALE
APP_FALLBACK_LOCALE LOG_CHANNEL=stderr LOG_LEVEL LOG_STDERR_FORMATTER
TRUSTED_PROXIES APP_MAINTENANCE_DRIVER=cache APP_MAINTENANCE_STORE=redis
OCTANE_SERVER=frankenphp DB_CONNECTION=pgsql DB_SSLMODE AUTO_MIGRATE
SESSION_DRIVER=redis SESSION_LIFETIME CACHE_STORE=redis QUEUE_CONNECTION=redis
PULSE_INGEST_DRIVER=redis PENNANT_STORE=database REDIS_CLIENT=phpredis
REDIS_HOST REDIS_PORT REDIS_DB=0 REDIS_CACHE_DB=1 REDIS_WAITING_ROOM_DB=2
BROADCAST_CONNECTION=reverb REVERB_APP_PROVIDER=brands REVERB_HOST
REVERB_PORT=8080 REVERB_SCHEME=http REVERB_SERVER_PATH REVERB_SCALING_ENABLED
REVERB_APP_MAX_CONNECTIONS MEDIA_DISK INVOICES_DISK EXPORTS_DISK
FILESYSTEM_DISK AWS_DEFAULT_REGION AWS_ENDPOINT AWS_USE_PATH_STYLE_ENDPOINT
AWS_MEDIA_BUCKET AWS_MEDIA_URL AWS_MEDIA_PUBLIC_DIRECT AWS_INVOICES_BUCKET
AWS_EXPORTS_BUCKET MAIL_MAILER MAIL_HOST MAIL_PORT MAIL_SCHEME
MAIL_FROM_ADDRESS MAIL_FROM_NAME HORIZON_PATH PULSE_PATH HEALTH_TO_ADDRESS
BACKUP_DESTINATION_DISK BACKUP_NOTIFICATION_EMAIL SENTRY_ENVIRONMENT
SENTRY_RELEASE SENTRY_TRACES_SAMPLE_RATE VAPID_SUBJECT INERTIA_SSR_ENABLED
INERTIA_SSR_URL`

### Secrets (OpenBao → ESO → `Secret`, referenced by name)

| Secret (values key) | Keys | Notes |
| --- | --- | --- |
| `app` | `APP_KEY`, `APP_PREVIOUS_KEYS`, `HEALTH_ENDPOINT_SECRET`, `BACKUP_ARCHIVE_PASSWORD` | `APP_KEY` generated once, never by the chart (B12) |
| `database` | CNPG `<cluster>-app`: `username password dbname host port uri` | mapped to `DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD` via `secretKeyRef`, or `uri` → `DB_URL` (append `?sslmode=verify-full&sslrootcert=…` with the CNPG CA mounted) |
| `redis` | `REDIS_PASSWORD` (`REDIS_USERNAME` for ACL users) | |
| `storage` | `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` (+ per-purpose `AWS_MEDIA_*`, `AWS_INVOICES_*`, `AWS_EXPORTS_*` overrides) | |
| `mail` | `MAIL_USERNAME`, `MAIL_PASSWORD` | operator fallback transport only; brand transports are DB rows |
| `payments` | `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` | Mollie/Unzer keys are brand data |
| `push` | `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` | generated once with `messaging:vapid-keys`; rotation invalidates subscriptions |
| `social` | `STEAM_API_KEY` | optional |
| `sentry` | `SENTRY_LARAVEL_DSN` | optional |
| `reverb` | `REVERB_APP_ID/KEY/SECRET` | only for the `config` provider; empty with `brands` |

Chart shape: `secrets.<group>.existingSecret: <name>`; with
`externalSecrets.enabled=true` the chart renders one `ExternalSecret` per
group from `externalSecrets.storeRef` and `secrets.<group>.baoPath`
(OpenBao KV v2; ESO's `vault` provider is OpenBao-compatible).

## 3. External services and what the chart assumes of them

| Service | Requirement |
| --- | --- |
| PostgreSQL | CloudNativePG `Cluster`; **PostgreSQL ≥ 15** (`UNIQUE NULLS NOT DISTINCT`); the app role owns the database so it can `CREATE EXTENSION btree_gist` (trusted extension, needed for ADR-0020 exclusion constraints); if a CNPG `Pooler` is used, session mode or transaction mode with `max_prepared_statements > 0` (PDO pgsql uses server-side prepared statements) |
| Redis | 7+, **standalone endpoint** (B6), `noeviction`, AOF, AUTH; sized for sessions + cache + queue + waiting room |
| Object storage | S3-compatible (Hetzner Object Storage, Garage): public bucket for media (anonymous read; `AWS_MEDIA_PUBLIC_DIRECT=true` only when verified), private bucket for invoices + exports (Object Lock/versioning where the provider offers it, ADR-0043 point 8), a **separate** backup destination if spatie backups stay on |
| SMTP | operator fallback relay (`MAIL_*`) |
| OpenBao + ESO | KV v2 mount, a `ClusterSecretStore`/`SecretStore` with the `vault` provider, Kubernetes auth for the chart's ServiceAccount or a token |
| Ingress | websocket-capable controller, cert-manager (or on-demand TLS via `/caddy/ask`), forwarded headers as above |
| Optional | Sentry (self-hosted or DPA, `send_default_pii=false`), Stripe webhooks reaching the brand host, an external monitor for `/health-json` |

## 4. Decisions needed

| # | Question | Recommendation |
| --- | --- | --- |
| D1 | Inertia SSR in production? | Off for the first release (`INERTIA_SSR_ENABLED=false`); add the `ssr` role when a page needs it |
| D2 | Redis: standalone-only, or move DB split to key prefixes? | Standalone with an operator-managed replica now; prefix refactor only if Cluster becomes a requirement |
| D3 | Ingress hosts: values list / wildcard / on-demand-TLS proxy? | Values list + cert-manager for release (adding a brand domain = a values change); revisit with Caddy + `/caddy/ask` when domains churn |
| D4 | Backups: CNPG for DB + spatie for files, or CNPG only? | CNPG for the database; spatie for `storage/` only when local disks are used, otherwise off (object storage has its own durability story) |
| D5 | Default storage: S3 or RWX PVC? | S3 default; local-disk mode supported behind `storage.local.enabled` with a documented RWX requirement |
| D6 | Secrets: chart-rendered `ExternalSecret`s or pre-created `Secret`s only? | Both: `existingSecret` always works; `externalSecrets.enabled` renders them for OpenBao |
| D7 | Where does the Dockerfile live? | In the konventix repo, built by its CI; the chart pins `image.repository`/`tag` |
| D8 | Migrations: hook Job with expand/contract, or maintenance window? | Hook Job + expand/contract policy; maintenance-mode toggle in values as the escape hatch |

## 5. What can start now in `konventix-helm`

Chart skeleton, templates, `values.schema.json`, `helm lint`/`helm template`
tests and the ESO/CNPG wiring do not depend on B1–B5 and can be built against
a placeholder image. End-to-end verification (install → bootstrap → log in on
the admin host → brand host renders) needs B1, B2 and B4.

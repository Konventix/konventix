# Konventix

Event-agnostic event management platform for the DACH market — registration, admission,
commerce, staffing, resources, content and competitions. One operator runs multiple
**brands** (each with its own domains, theme and module set), each brand runs multiple
**events**. Brands are resolved by request host; this is brand *scoping*, not multi-tenancy.

Laravel 13 + Inertia 3 + Vue 3 + Tailwind v4, built as a **modular monolith**.

Copyright © 2026 Markus Kohn. Licensed under the GNU Affero General Public License,
version 3 or later — see [LICENSE](LICENSE). Copyright is held solely by the author,
which keeps dual licensing open (ADR-0011); external contributions require a CLA.

## Architecture

Modules are path-repository composer packages under `app-modules/<kebab-name>/`
(InterNACHI/modular), namespaced `Konventix\<StudlyName>\`. `app/` (`App\`) is the host
layer only: framework wiring, global middleware, the base controller.

```
app-modules/<name>/
├── SPEC.md                 what the module does — stable
├── TODO.md                 tasks — updated in the PR that changes the code
├── composer.json           declares its own ServiceProvider (extra.laravel.providers)
├── routes/web/<name>.php   loaded only by the module's provider (see below)
├── database/               migrations, factories, seeders
├── resources/js/           pages, components
├── src/
│   ├── Providers/          <Name>ServiceProvider extends ModuleServiceProvider
│   ├── Models/             incl. read models of foreign data
│   ├── Data/               laravel-data: validation + Inertia props + TS types
│   ├── Enums/              incl. Permission.php (capability catalog)
│   ├── Actions/            business logic, DB::transaction inside
│   ├── Events/             the module's public contract
│   ├── Listeners/          reactions to other modules' events
│   ├── Http/               Controllers, Middleware
│   ├── Policies/  Navigation/  Gdpr/
└── tests/
```

Tiers (see `docs/architecture/module-map.md`):

| Tier | Modules | Gating |
| --- | --- | --- |
| Kernel | platform, identity, event, compliance, messaging | always on (`$gateable = false`) |
| Core | registration, admission, commerce, content | always on (`$gateable = false`) |
| Modules | staffing, resources, competition, game-ops, agenda, community, sponsoring, newsletter, extensibility | Pennant feature per brand |

**Dependency direction:** every module may depend on `platform`; nothing else crosses a
module boundary in code. No cross-module Eloquent imports — foreign entities are referenced
by ULID, and a module that needs foreign display data keeps its own read model fed by the
owning module's events. Events are the only public contract, and carry IDs and scalars,
never models.

**Registration vs. activation:** a module always registers itself at boot (routes, policies,
listeners, navigation, Pennant features, GDPR exporters) through its own provider extending
`Konventix\Platform\Modules\ModuleServiceProvider`. Whether it is *active* is a request-time
per-brand Pennant gate at four points: routes, navigation, UI slots, listeners. Modules are
deliberately absent from `bootstrap/providers.php`.

Route files live in `routes/web/`, one level deeper than Laravel's default: modular
auto-`require`s every `routes/*.php` with no middleware at all, which would let a route
escape both the `web` group and the activation gate.

Brand-owned models use the `BelongsToBrand` trait (global `BrandScope` + `brand_id` stamped
on create); manual `where('brand_id', ...)` scoping is forbidden. Every model has a ULID
primary key.

## Theming

`resources/css/app.css` defines design tokens in two layers:

1. Raw custom properties on `:root` / `.dark` — `--surface`, `--surface-raised`, `--text`,
   `--text-muted`, `--accent`, `--on-accent`, `--border`, `--ring`, `--danger`, `--success`,
   `--warning`, sidebar and chart tokens. This is the only layer a brand overrides.
2. `@theme inline` maps them to Tailwind utilities (`bg-surface`, `text-text-muted`,
   `border-border`, …). Because the mapping is `inline`, a runtime `:root` override
   re-colors the whole app.

Never write palette utilities (`bg-zinc-800`, `text-gray-500`) or arbitrary colors
(`bg-[#1b1b18]`) — the `konventix/no-palette-colors` ESLint rule fails on them.
`--color-background`, `--color-primary`, `--color-destructive` and friends are shadcn-vue
compatibility aliases so `shadcn-vue add` keeps producing working components; prefer the
Konventix names in code you write yourself. Note that shadcn's `accent` (a subtle hover/focus
fill) is mapped to `subtle` here, because `accent` is the brand accent.

Server-side brand injection has its slot in `resources/views/app.blade.php`: a `brandTokens`
map renders as a `:root` override, with token names and values whitelisted because that
content is brand-controlled.

## Development

First run on the Docker stack — **the order matters, see the warning below**:

```bash
cp .env.example .env                      # MUST precede `sail up`
composer install                          # host-side, only to get vendor/bin/sail
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail npm install && ./vendor/bin/sail npm run build
```

> **Copy `.env` before the first `sail up`.** compose.yaml feeds `${DB_USERNAME}` and
> `${DB_DATABASE}` to the pgsql container, and Postgres only reads them during first-time
> `initdb`. Start the stack without a `.env` and those interpolate to empty strings, so the
> cluster initializes as the default `postgres` role — after which every boot logs
> `Skipping initialization` and ignores the correct credentials forever. Migrations then fail
> with `SQLSTATE[08006] … role "konventix" does not exist`, which reads like a connection
> fault but is a permanently mis-seeded volume. Recovery is
> `docker compose down && docker volume rm konventix-poc_sail-pgsql`, then start over.
> Note `composer setup` is what *creates* `.env`, so running it first does not help — on
> Docker it also needs the containers to already be up.

Without PHP on the host, bootstrap `vendor/` in a container instead of `composer install`:

```bash
docker run --rm -v "$(pwd)":/opt -w /opt laravelsail/php85-composer:latest composer install
```

Day to day:

```bash
composer setup     # install, key, migrate, npm install, build — native/Herd, not Sail
composer dev       # serve + queue + vite
composer check     # pint --test, phpstan, pest — what CI runs
php artisan make:module <name>          # scaffold a module from stubs/app-modules/
php artisan make:<thing> --module=<name>  # generate into a module, not app/
```

Mail in the dev stack goes to Mailpit, not out: the `mailpit` service catches every send, and
its inbox is at `http://localhost:8025`. A send only leaves the `messaging` queue once the
`queue` container is up, so check that first if the inbox stays empty.

Developing against object storage (ADR-0043) is opt-in in the application, not in the stack:
the `garage` container comes up with everything else, but media and invoices stay on local
disks until you point the disks at it.

```bash
# .env: the Garage disk block from .env.example (MEDIA_DISK=media_s3, …)
./vendor/bin/sail up -d
./vendor/bin/sail artisan storage:garage-init   # buckets, grants, website access — idempotent
./vendor/bin/sail artisan storage:doctor        # probes every configured disk
./vendor/bin/sail artisan storage:migrate       # moves existing files, verifying each copy
```

Garage applies its own cluster layout and creates the first key and bucket at boot; the init
command adds the second bucket and the anonymous read access that Garage only offers through
website mode, having neither bucket policies nor ACLs.

**compose.yaml deliberately defines no compose profiles.** `vendor/bin/sail` has no
`--profile` handling anywhere in it — it forwards extra `-f` files through `SAIL_FILES` and
nothing else — so a profile-gated service is unreachable through the command this project
uses to drive the stack, and `COMPOSE_PROFILES` in `.env` only worked when you remembered to
set it. Everything in the file therefore starts on `sail up`. If you want a service optional
again, the sail-native way is a second compose file plus `SAIL_FILES=compose.extra.yaml`, not
a profile.

### Stripe webhooks in local development

Webhooks are the only source of settlement truth (ADR-0039), so a manual purchase walkthrough
against a real PSP has to receive them — but Mollie's and Unzer's webhook URLs are built from
`app.url`, which those providers cannot reach from a WSL dev stack (no inbound tunnel is
installed here; that's `app-modules/commerce/TODO.md`'s "Local-dev webhooks" line, and
Mollie/Unzer stay out of scope). Stripe doesn't have that problem: `stripe listen` opens an
*outbound* connection to Stripe and replays events over it, so nothing needs to reach in.

```bash
stripe login                    # once per host
./contrib/stripe-listen.sh      # forwards to POST /api/commerce/webhooks/stripe
```

The script fails early with a clear message if the Stripe CLI is missing or not logged in,
reads `APP_PORT` from `.env` (falling back to compose's default of 80), and restricts events
to the ones `StripeWebhookController` handles or is built to handle next: checkout completion
and its async/expiry siblings, disputes, and refunds. They all fire on the instance's own
Stripe account — Konventix settles into one Stripe account per instance and uses no connected
accounts (ADR-0058) — so a plain `--forward-to` tunnel sees everything a purchase walkthrough
tests. Run `./contrib/stripe-listen.sh --secret` to print only the webhook signing secret —
for pasting into `STRIPE_WEBHOOK_SECRET` without starting a listener. Sail/Octane reads `.env`
live in dev, but a cached config does not follow along; run `sail artisan config:clear` if
`STRIPE_WEBHOOK_SECRET` was already cached.

The keys themselves are this instance's Stripe account, not a platform pair: `STRIPE_SECRET`
and `STRIPE_WEBHOOK_SECRET` from the environment, or — winning over them, per value — the
pair an operator enters under **Settlement accounts → Stripe account** in the console, stored
`APP_KEY`-encrypted. That precedence is the trap this script walks into: if you have used
that card, the `whsec_` the tunnel prints belongs in the console too, not only in `.env`, or
every forwarded event comes back as a 400 signature failure while `.env` looks right.
**Clear keys** on the same card deletes the row and hands the environment back. A deployed instance has no tunnel and needs a real webhook endpoint
instead (created *without* "Listen to events on connected accounts"); where both values come
from in the Stripe dashboard, and why each environment carries its own signing secret, is
[Stripe credentials per environment](docs/helm-chart-requirements.md#stripe-credentials-per-environment).

### Load testing the rush

The rush from ADR-0047 point 6 — 1,000 arrivals against the seeded "Rush" brand, each one
logging in, queueing, registering, buying a shirt and checking out — lives in
`tests/Load/ticket-rush.js` and streams its metrics into Grafana while it runs:

```bash
./vendor/bin/sail artisan konventix:seed --rush     # the Rush brand, event and shop item
docker compose run --rm k6 run /scripts/ticket-rush.js
```

Then open **http://localhost:3000** — Grafana needs no login and opens straight on the
"Ticket rush (k6)" dashboard. The six tiles across the top read the whole selected time
range, so they still show the run's totals after it finishes; the graphs below are windowed
and are what show you *where* a run bends. The panel worth watching is "Throughput by
response status": a 429 band means the waiting room or the per-IP cap is refusing arrivals,
a red 5xx band means the app is actually falling over.

The `k6` service is a `run` target, not a server. It sits at `Exited (0)` after `sail up`
because its default command is `k6 version`; the command above replaces it. Metrics go to
Prometheus (`http://localhost:9091`, a sink only — it scrapes nothing) via k6's remote-write
output, which compose wires up through `K6_OUT`; pass `-e K6_OUT=` to skip the dashboard for
one run. Either way `handleSummary` still writes `tests/Load/results/summary.json`, and that
file — not the dashboard — is what `tests/Load/RushResultTest.php` asserts against.

Default profile is 40 VUs × 25 sequential arrivals rather than 1,000 simultaneous
connections, which collapse this single-process dev container; `VUS=1000 ARRIVALS_PER_VU=1
docker compose run --rm -e VUS -e ARRIVALS_PER_VU k6 run /scripts/ticket-rush.js` reproduces
the all-at-once profile. Grafana's dashboard is provisioned read-only from
`contrib/grafana/`; edit a panel live if it helps, then export the JSON back into that
directory or the next container rebuild loses it.

#### Running the rush against the production image

The dev stack is the wrong thing to measure — ROADMAP slice 24 said as much, and ADR-0049's
consequences name the image as what makes an honest run possible. `compose.prod-local.yaml`
is that rig: the same artifact CI builds, every role from it, its own compose project and its
own volumes, so it can never touch the dev stack.

```bash
docker build -t konventix:local .
docker compose -p konventix-prod -f compose.prod-local.yaml up -d      # migrate, install, seed, all roles
docker compose -p konventix-prod -f compose.prod-local.yaml run --rm k6 run /scripts/ticket-rush.js
```

`up` runs `konventix:migrate` and `konventix:install` as one-shots the way the chart's
pre-upgrade Job and post-install hook do, then seeds the rush fixture, then starts web,
horizon, scheduler, pulse and reverb. The k6 service defaults to the **full** profile there
(`VUS=1000 ARRIVALS_PER_VU=1`) — override with `VUS=… ARRIVALS_PER_VU=…` in the environment.
Grafana is on the same http://localhost:3000 and the app itself answers on
http://localhost:8000.

Two things about that rig are not a real deployment, both explained at length in the file's
header: it runs `APP_ENV=local`, because `CommerceServiceProvider` deliberately gives the
`null` settlement provider a hosted checkout only outside production (ADR-0047 point 6) and
the rush cannot otherwise complete a cart; and the fixture is seeded by the Sail image,
because seeders use factories, factories need faker, and faker is `require-dev` and therefore
absent from the artifact by design. Everything actually serving the load — dependencies,
bundle, entrypoint caching, FrankenPHP, the non-root user — is the artifact.

Afterwards, the database half of the result is a test:

```bash
docker compose -p konventix-prod -f compose.prod-local.yaml run --rm \
    --entrypoint php seed artisan test --group=load tests/Load/RushResultTest.php
```

It reads `tests/Load/results/summary.json` next to the live `konventix` database and asserts
what the rush must never violate: zero 5xx, quota consumed exactly 300, stock never past 200,
and no more orders than arrivals the waiting room admitted.

Requires PostgreSQL (see `.env.example`). Tests run against a separate `testing` database on
the same Postgres server — `phpunit.xml` overrides `DB_DATABASE` only, so the connection stays
`pgsql`; Sail's `create-testing-database.sql` creates it during `initdb`. Module feature tests
live in the module's own `tests/` directory (`phpunit.xml` testsuite `Modules`).

## Conventions

- `declare(strict_types=1)` in every PHP file — enforced by Pint (`pint.json`).
- PHPStan level 7 over `app/`, `app-modules/`, `bootstrap/`, `config/`, `database/`, `routes/`.
  `tests/` is deliberately out of scope.
- One spatie/laravel-data object per form/endpoint: single source for validation rules,
  Inertia props and generated TypeScript types. Never hand-write TS types for backend payloads.
- Business logic in Actions (`DB::transaction` inside the Action), not controllers.
- `Model::shouldBeStrict()` is on outside production — fix N+1s rather than disabling it.
- Capabilities are enums (`src/Enums/Permission.php` per module), roles are brand-scoped data
  via spatie/laravel-permission with `teams` bound to `brand_id`.
- TypeScript `strict` mode is on.
- Wayfinder generates `resources/js/actions/**` and `resources/js/routes/**`; both are build
  output. Re-run `php artisan wayfinder:generate --with-form` after moving or renaming a
  controller, and never hardcode URLs in the frontend.

## Open TODOs

- **Brand middleware** — `ResolveBrand` exists and is tested, but it is not appended to the
  `web` stack in `bootstrap/app.php`, and nothing shares `$brandTokens` with the root view,
  so brand injection renders nothing in a real request.
- **Brand palette** — the default token values in `resources/css/app.css` are the neutral
  starter-kit greys; a Konventix house palette has not been chosen.
- **Logo & favicons** — `resources/js/components/AppLogoIcon.vue`, `public/favicon.ico`,
  `public/favicon.svg`, `public/apple-touch-icon.png` are still Laravel starter-kit artwork.
- **Module frontends** — `config/inertia.php` only resolves pages from `resources/js/pages`;
  module `resources/js/pages` directories are not wired into page resolution yet.
- **Module contents** — most modules beyond `platform` and `identity` are scaffolds with a
  provider, a `SPEC.md` and a `TODO.md`. Start from the module's `SPEC.md`.

## Further reading

- `CLAUDE_extra.md` — the hard rules, in the form agents are expected to follow.
- `docs/architecture/overview.md` — system model, request flow, deployment.
- `docs/architecture/module-map.md` — which module owns what (and the LanCore mapping).
- `docs/decisions/` — ADRs; these are settled, do not re-litigate them in code.
- `docs/lessons-from-lancore.md`, `docs/ROADMAP.md`.
- `.ai/rules/` — per-path rules; read the ones matching what you are editing.

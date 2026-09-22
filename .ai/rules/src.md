---
paths:
  - 'app-modules/**'
---

# Modules

## Domain code lives in app-modules/ under Konventix\, not in app/
Every module is a path-repository composer package at `app-modules/<kebab-name>/`
(InterNACHI/modular). `config/app-modules.php` sets `modules_namespace` to `Konventix`,
so `app-modules/game-ops/src/` is `Konventix\GameOps\`. `app/` (`App\`) is the host layer
only: framework wiring, global middleware, the base controller.

Canonical layout: `SPEC.md`, `TODO.md`, `composer.json`, `routes/web/<name>.php`,
`src/{Providers,Models,Data,Enums,Actions,Events,Listeners,Http,Policies,Navigation,Gdpr}`,
`database/{migrations,factories,seeders}`, `resources/js/{pages,components}`, `tests/`.
`app-modules/identity/` is the reference module.

Trap: `php artisan make:*` writes into `app/` unless you pass `--module=<name>`. Scaffold a
whole module with `php artisan make:module <name>` so the stubs in `stubs/app-modules/`
apply — hand-built modules end up missing the `routes/web/` layout.

Trap: never `use` another module's Eloquent model. Reference foreign entities by ULID; if
you need foreign display data, keep a read model in your own module, populated by listening
to the owning module's events (ADR-0004). Events are the only public contract between
modules, and their payloads carry IDs and scalars, never models.

## Every date this application hands you is a `CarbonImmutable`, and the models' `@property Carbon` lines say otherwise
`AppServiceProvider::configureDefaults()` calls `Date::use(CarbonImmutable::class)`, so a `datetime` cast, `created_at`/`updated_at` and `now()` all return `Carbon\CarbonImmutable`. That is a **sibling** of `Illuminate\Support\Carbon`, not a subclass, so a parameter, property or return typed `Carbon` rejects it at runtime with a TypeError. Meanwhile most models' `@property` blocks still say `Carbon|null`, which means PHPStan sees no mismatch at all and the first sign is a red pipeline — `ReplayEventDetailsCommand`'s `--with-state` helper shipped exactly that way, its `?Carbon` closure parameter surviving only the calls that passed `null`. Type these `Carbon\CarbonInterface` (both implement it), or `CarbonImmutable` where you genuinely mean the immutable one. Note that both kinds really are in circulation: a cast or `now()` answers immutable, while an explicit `Illuminate\Support\Carbon::parse()` bypasses the factory and answers mutable — which is why `Event::startsAtInstant(): Carbon` is correct as written and a `CarbonImmutable` from `$event->starts_at` is too.

## Response headers that must reach the webhooks go on the global stack
`SecurityHeaders` (#251) is appended in `bootstrap/app.php` with
`$middleware->append()`, not `$middleware->web()`. The surfaces that need `nosniff`
and the framing policy most are the ones outside the `web` group:
`GET /media/{media}` (unauthenticated, brand origin), the three PSP webhooks under
`api/`, `/up` and `/health-json`. HSTS is the one header gated on
`$request->isSecure()`, so local HTTP development is unaffected.

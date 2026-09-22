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

## Response headers that must reach the webhooks go on the global stack
`SecurityHeaders` (#251) is appended in `bootstrap/app.php` with
`$middleware->append()`, not `$middleware->web()`. The surfaces that need `nosniff`
and the framing policy most are the ones outside the `web` group:
`GET /media/{media}` (unauthenticated, brand origin), the three PSP webhooks under
`api/`, `/up` and `/health-json`. HSTS is the one header gated on
`$request->isSecure()`, so local HTTP development is unaffected.

## Domain events raised inside a transaction are dispatched via `DB::afterCommit()`
`event()` is not transactional. An Action that calls it inside `DB::transaction()`
and then throws — a later guard, a nested Action refusing — rolls the rows back
while every listener has already been told, so a contract event announces a
result, a correction or a withdrawal that never happened. Build the event where
the data is, and hand the dispatch to `DB::afterCommit()`: it runs at the
outermost commit and is dropped on rollback, including a rollback of a savepoint
the Action was nested in. The reference is Resources:
`AssignPlacesToRegistration` (`SeatClaimPlaced`), with `HoldResourceBooking` and
`ConfirmResourceBooking` alongside; it also captures the ambient brand and
restores it around the deferred dispatch, which a listener that reads
`BrandContext` needs. Outside a transaction `afterCommit()` runs the callback at
once, so the call is safe in a concern whose callers may or may not open one.
Under `RefreshDatabase` the callbacks fire when the Action's own transaction
commits (tests.md), so `Event::fake()` still sees a committed dispatch and a test
can assert `assertNotDispatched` after a refusal. The defect reached review three
times in 0.9.0 before it was written down.

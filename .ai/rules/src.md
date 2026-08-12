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

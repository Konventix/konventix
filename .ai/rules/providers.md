---
paths:
  - 'app-modules/*/src/Providers/**'
---

# Providers

## One provider per module, extending Platform's ModuleServiceProvider
`app-modules/<name>/src/Providers/<Name>ServiceProvider.php` extends
`Konventix\Platform\Modules\ModuleServiceProvider`, implements `moduleName()` (kebab-case,
identical to the directory name), and overrides only the hooks it needs: `registerBindings`,
`policies`, `registerListeners`, `registerNavigation`, `registerFeatures`,
`registerGdprExporters`. Module wiring never goes into a central provider (ADR-0003).

Modules are **not** listed in `bootstrap/providers.php` — each declares itself in its own
`composer.json` under `extra.laravel.providers`.

Kernel (platform, identity, event, compliance, messaging) and Core (registration, admission,
commerce, content) set `protected bool $gateable = false`. The other nine stay gateable, and
the base class then adds `ensure-module:<name>` to their routes by itself — never write that
middleware into a route file.

Trap: module route files live in `routes/web/`, not `routes/`. InterNACHI/modular
auto-`require`s every `routes/*.php` at depth 0 with no middleware at all, which would let a
route escape both the `web` group and the activation gate. One directory deeper is invisible
to that finder, so the provider is the only thing that can register module routes. Declare
bare routes in the file, do not re-apply `web` or the gate, and never require module routes
from `routes/web.php`.

## A boot-time singleton never constructor-injects a scoped binding
`BrandContext` and `OperatorContext` are bound `scoped()`, not `singleton()` (see
`PlatformServiceProvider::registerBindings()`): under Octane they are forgotten after every
request, but a class bound `singleton()` — `ModuleGate`, `NavigationRegistry`, `SlotRegistry`,
`BlockRegistry` — keeps whatever it captured at construction for the worker's entire lifetime.
Constructor-injecting `BrandContext` into one of these pins the first request's brand into the
worker forever; every later request reads that same stale brand. Resolve the scoped binding at
call time instead — `app(BrandContext::class)->current()` inside the method that needs it, never
stored on `$this` — the pattern `BlockRegistry::resolveActive()` established first. Reproduce the
bug in a test with `app()->forgetScopedInstances()` between two simulated requests.

---
paths:
  - 'app-modules/*/src/Listeners/**'
---

# Listeners

## Listeners register explicitly; modular event discovery is off
Register every listener with Event::listen in the module's own ServiceProvider (ADR-0003). InterNACHI modular's event discovery is disabled (`config/app-modules.php` → `should_discover_events => false`): it scans the same `src/Listeners/` directories and would register everything a second time, so each listener ran TWICE per event. Idempotent projections masked this for months; Messaging's send listeners surfaced it as duplicate mail. Do not re-enable discovery, and do not rely on it as registration — a listener without an Event::listen line never fires.

## A listener resolves scoped bindings at call time, never in its constructor
The event dispatcher is a boot-time singleton that builds listener classes with the container it was constructed with. Under Octane that is the worker's base application, while every request runs in a cloned sandbox whose scoped instances (`BrandContext`, `OperatorContext`) are the ones the middleware filled — so a listener that constructor-injects `BrandContext` reads an empty context on every Octane request and silently does nothing. Tests never notice: there both containers are the same object. `RecordBrandMembership` did exactly this for three weeks and no membership was ever written on the dev cluster (#142). Call `app(BrandContext::class)` inside `handle()` instead, and reproduce the bug in a test by building the listener from `clone app()` after `app()->forgetScopedInstances()` while the brand is set on the current application only (`BrandMembershipTest`).

---
paths:
  - 'app-modules/*/src/Listeners/**'
---

# Listeners

## Listeners register explicitly; modular event discovery is off
Register every listener with Event::listen in the module's own ServiceProvider (ADR-0003). InterNACHI modular's event discovery is disabled (`config/app-modules.php` → `should_discover_events => false`): it scans the same `src/Listeners/` directories and would register everything a second time, so each listener ran TWICE per event. Idempotent projections masked this for months; Messaging's send listeners surfaced it as duplicate mail. Do not re-enable discovery, and do not rely on it as registration — a listener without an Event::listen line never fires.

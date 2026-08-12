# ADR-0003: Module registration ≠ activation

**Status:** accepted

## Context

Conflating "code is loaded" with "feature is enabled" causes architectural
problems: conditional service registration, boot-order bugs, and untestable
half-registered states.

## Decision

- **Registration always happens at boot.** Every installed module's
  ServiceProvider runs unconditionally and registers routes, policies,
  listeners, navigation entries, Pennant features, and GDPR exporters.
- **Activation is a request-time gate per brand** via Laravel Pennant,
  enforced at exactly four points:
  1. **Routes** — module route groups carry an `ensure-module:<name>` middleware.
  2. **Navigation** — the Platform nav registry filters by active features.
  3. **UI slots** — slot rendering checks the feature.
  4. **Listeners** — module listeners short-circuit when the feature is off
     for the event's brand context.

Central wiring is forbidden: no module code in a global AppServiceProvider
(LanCore's 408-line central provider is the anti-example).

## Consequences

- A disabled module costs a feature check, not a code path audit.
- Modules are testable in isolation with the feature force-enabled.
- Per-brand and per-event feature scoping both go through Pennant.

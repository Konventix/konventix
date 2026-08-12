# ADR-0004: Events are the only contract between modules

**Status:** accepted

## Context

LanCore's DDD boundaries existed on paper only: Competition imported 25 foreign
models, Shop 24, Event 21. `User` became a god object importing models from 13
domains with 30 methods. The one place the rule was kept (Orchestration ↔
Competition) proved it works.

## Decision

- A module never imports another module's Eloquent models. Foreign entities are
  referenced by ULID as plain values.
- Modules communicate exclusively through internal Laravel events. Payloads
  contain IDs and scalars, never models.
- When a module needs foreign display data or denormalized state, it maintains
  its own **read model** (e.g. `StafferProfile`, `ShopperProfile`), populated by
  listeners on the owning module's events.
- `Identity`'s User stays slim: auth, profile, preferences. Domain-specific
  relations live in the modules as read models, not on User.

Enforcement: an architecture test (Pest arch / deptrac-style) fails the build on
cross-module model imports.

## Consequences

- Some data duplication by design; consistency is eventual via events.
- Listeners that project read models must be idempotent and replayable.
- Deleting/anonymizing a user fans out through Compliance events to each
  module's `Gdpr/` handler.

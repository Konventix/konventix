# ADR-0014: ULID primary keys everywhere

**Status:** accepted

## Context

ADR-0004 requires modules to reference foreign entities "by ULID as plain
values" rather than by Eloquent relation. That rule only holds if the identifier
a module hands out is in fact the primary key — otherwise every cross-module
reference needs a lookup to translate between an internal auto-increment ID and
an external ULID, and the two will drift.

Auto-increment keys also leak business information across brand boundaries
(order counts, registration volume) in a system where IDs appear in URLs served
on public per-brand domains.

## Decision

**Every model uses a ULID primary key.** No auto-increment integer keys, no
"int PK plus a public ULID column" split.

- Models use Laravel's `HasUlids` trait; migrations use `$table->ulid('id')->primary()`.
- Foreign references — including cross-module ones — are `ulid` columns.
- Cross-module foreign columns carry **no database foreign key constraint**:
  the owning module may be inactive for a brand, and a constraint would make
  module boundaries a schema-level coupling. Within a module, foreign keys are
  used normally.
- Route model binding uses the ULID directly; no separate slug/UUID field.

The one deliberate exception is framework-owned infrastructure tables Laravel
ships and manages itself (jobs, cache, sessions, failed_jobs). We do not fight
the framework there.

## Consequences

- Cross-module references are stable, copy-pasteable values that mean the same
  thing in an event payload, a URL, and a database column.
- 26-character keys cost more storage and index space than integers. Accepted:
  at event-management volumes this is not the constraint that will hurt us.
- ULIDs are lexicographically sortable by creation time, so `order by id` stays
  meaningful and we do not need a separate sequence column for insertion order.
- Because ULIDs encode a millisecond timestamp, an ID exposed publicly reveals
  *when* a record was created. This is acceptable for events, registrations, and
  content; if a future entity needs creation time hidden, that entity — not the
  convention — gets a UUIDv4.
- Factories and tests must not assume numeric IDs.

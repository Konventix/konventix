# ADR-0027: Event times are stored wall-clock with an explicit timezone

**Status:** accepted

## Context

Event's SPEC flagged time storage as a real decision: are event-local times
stored as UTC instants with a display timezone, or stored wall-clock with the
timezone as data? Rescheduling across a DST boundary — or a jurisdiction
changing its DST rules between announcement and doors — makes the two models
diverge observably.

## Decision

**Events store wall-clock local time plus an IANA timezone column**
(`starts_at`/`ends_at` as timezone-less datetimes, `timezone` as data).

- An organizer promises "doors open 10:00". That promise is wall-clock: if the
  timezone's offset rules change after publication, the printed schedule, the
  website and the door must still say 10:00. Storing UTC would silently shift
  the local time in exactly that case.
- Conversion to instants happens at read time, through a single accessor on the
  model — never inline `Carbon::parse` in consuming code.
- Ordering and "is it running now" comparisons convert on demand; at this
  domain's scale (an operator's events, not millions of rows) the computed
  conversion is not a query-planning concern. If a sortable instant column is
  ever needed, it is a derived, denormalized column — the wall-clock value
  stays authoritative.

This applies to **event-domain scheduling times** (events, agenda items,
shifts, resource bookings inherit the pattern via their own ADRs if needed).
System timestamps (`created_at`, queue timestamps, audit trail) remain UTC
instants as everywhere else in Laravel.

## Consequences

- `EventRescheduled` carries wall-clock values plus the timezone, matching
  what the organizer edited.
- Cross-timezone listings (an operator's brands in different countries) must
  convert per event for display; there is no single sortable instant column
  unless one is derived.
- Duration math across a DST transition follows local reality (a shift over
  the spring-forward night is an hour shorter as experienced) — code that
  needs elapsed time must diff instants, not wall-clock values.

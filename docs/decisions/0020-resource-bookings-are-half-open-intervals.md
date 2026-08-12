# ADR-0020: Resource bookings are half-open intervals

**Status:** accepted

## Context

Three questions about Resources were asked separately and only have coherent
answers together: what time dimension a booking has, how the no-overlap
invariant is enforced, and which modules create bookings at all. Answering any
one of them alone produces a decision the other two undo.

The starting point is that there is nothing to port. LanCore's `SeatAssignment`
is `(ticket, user, seat_plan, seat)` with two unique constraints — one on
`(plan, seat)`, one on `(ticket, user)`. It has no time dimension, no state, no
hold and no expiry. A seat is claimed for the whole weekend by exactly one
gamer, and the schema says so. Every interval, hold, state machine and expiry
concept in Konventix's Resources module is therefore new construction, not a
generalisation of working code, and the predecessor's test suite proves nothing
about any of it.

Resources' SPEC already commits to two things that constrain the answer. A
booking "always occupies an interval, even when that interval happens to be *the
whole event*" — one code path, never a special case. And conflict prevention is
"the module's core invariant… enforced in the persistence layer, not in a
controller". PostgreSQL is the deployment database and offers `EXCLUDE USING
gist` over a `tstzrange`; the test suite currently runs on in-memory SQLite,
which has neither range types nor exclusion constraints. LanCore got away with a
plain unique index because it had no intervals to overlap.

Meanwhile three modules will own time-boxed things that happen in a room:
`ResourceBooking`, `AgendaItem` and `Shift`. Agenda's SPEC says an item "points
at a resource by ULID"; Staffing's says a shift "references a resource by ULID".
Neither says a booking is created.

## Decision

**Every booking carries a half-open interval `[starts_at, ends_at)`, the
no-overlap invariant is a PostgreSQL exclusion constraint, and Agenda and
Staffing do not book.**

- One nullable-free pair of timestamps on every `ResourceBooking`. A whole-event
  booking is written as the event's own start and end: a degenerate interval,
  not a second shape. A shift, an agenda item and a seat are checked by the same
  predicate.
- `booking_granularity` on `ResourceType` constrains *input* — round to 15
  minutes, or force the event's full span — without changing storage. It is a UI
  hint, not a stored mode.
- `EventRescheduled` shifts whole-event bookings through an explicit, tested
  Action. The rewrite is real work and is treated as such rather than assumed
  free.
- The invariant is `EXCLUDE USING gist` over the resource and the interval, and
  the test suite moves off in-memory SQLite onto PostgreSQL. There is one
  implementation of the rule and it is the one production runs.
- Agenda items and shifts store a plain location reference by ULID. They create
  no `ResourceBooking` rows and Resources is not consulted when they are
  scheduled.

### Alternatives considered

**A fixed slot grid per `ResourceType`.** The type declares a slot length and
bookings reference slot rows, so the conflict check is a unique index on
`(resource_id, slot_id)` — fast, trivially expressible, and it works on SQLite,
which would have made the database question disappear entirely. Rejected because
a materialised slot table per resource per event is a great many rows, changing
the grid invalidates existing bookings, and "borrow this headset from 14:10 to
15:40" does not fit a grid without lying about the times.

**Two modes on `ResourceType`, `whole_event` or `interval`.** Seats stay exactly
as cheap as LanCore's and store no redundant timestamps. Rejected because it
breaks the SPEC's core promise on day one: two code paths and two conflict
checks, which is precisely the thing being avoided.

**An application-level check inside the Action under a pessimistic lock.**
Database-agnostic, SQLite tests keep working, and the failure surfaces as a
domain error rather than a constraint violation to translate. Rejected because
it is a controller-level guarantee wearing an Action's clothes — a raw insert, a
data fix or a second write path breaks it silently, which is the exact class of
bug the SPEC asks to have prevented in the persistence layer.

**Both: a driver-guarded constraint in production plus an application lock
everywhere.** Rejected because it is two implementations of one rule that must
agree, and the production-only constraint is never exercised by the test suite,
so the first time it fires is in production.

**Agenda and Staffing emit scheduling events; Resources creates the bookings and
emits a rejection on conflict.** One conflict check for the whole system, and
"what is in Hall B on Saturday" becomes one query. Rejected for release because
it makes both modules depend on Resources being active for the brand, requires a
second degraded code path for when the Pennant feature is off, and turns a
synchronous validation failure into an asynchronous rejection the source module
has to catch up with. Retained as the shape to adopt if the consequence below
turns out to bite.

## Consequences

- Every seat booking stores two timestamps that are redundant for the LAN case
  and must be rewritten whenever the event moves. "Is this seat free" becomes a
  range query rather than a row lookup. Accepted as the price of one code path.
- Moving the test suite to PostgreSQL is a project-wide cost, not a Resources
  cost: CI gains a database service, local setup gains a dependency, the suite
  gets slower, and migrations become PostgreSQL-specific so the project quietly
  stops being portable to MySQL. The upside is worth naming as plainly as the
  cost — the divergence between test storage and production storage disappears,
  so everything the suite proves, it proves against the engine that will run it.
- The constraint is bought at that project-wide cost, and then Agenda and
  Staffing — the two modules most likely to contend for rooms — are kept outside
  the booking system by the same decision. The exclusion constraint guarantees no
  double-booking *of bookable resources*: seats, booths, devices, which is where
  the money is. It guarantees nothing about rooms. A talk and a shift can claim
  the same room at the same time and nothing notices, by design, and "which room
  is free" is a question Resources cannot answer.
- If room contention turns out to matter, **revisiting the Agenda/Staffing
  decision is cheaper than revisiting the constraint.** The constraint is a
  migration and a CI change that will already have been paid for; the booking
  question is an event contract between three modules, two of which are not
  written yet.
- `booking_granularity` enforces nothing at the storage layer, so a booking
  produced by a data fix or a future code path can violate it. Deliberate: the
  invariant worth a constraint is non-overlap, not tidiness.
- Because none of this exists in LanCore, the hold/confirm/release state machine
  and its expiry behaviour have no prior art to check against. They need tests
  written from the specification rather than from observed behaviour, and that
  work sits on the critical path for Phase 2.

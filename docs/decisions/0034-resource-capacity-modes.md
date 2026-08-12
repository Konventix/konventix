# ADR-0034: Resource capacity modes — unit and quantity

**Status:** accepted

## Context

Resources' SPEC lists this as the first open question: "Is a resource with
capacity greater than one (a room for 40, a booth strip sold in square
metres) one row with a capacity column, or many rows of one unit each?
ADR-0005's exhibitor example books 'booth B12, 12 m²', which points at
quantity rather than unit — but a capacity column complicates the overlap
invariant."

ADR-0020 already settled the shape every booking takes — a half-open
interval `[starts_at, ends_at)` and a PostgreSQL exclusion constraint as the
single, database-enforced no-overlap invariant — and settled it specifically
for the case the exclusion constraint expresses cleanly: two rows for the
same resource may not claim overlapping intervals. That is exactly "one row
per unit." It says nothing about a resource whose capacity is a number
rather than a count of discrete rows, because LanCore's `SeatAssignment` had
no such case to generalize from — Resources' whole domain here is new
construction, same as ADR-0020 already found.

## Decision

**A `ResourceType` declares a capacity mode: unit or quantity. Unit mode is
one row per unit, and ADR-0020's exclusion constraint applies to it exactly
as written. Quantity mode is one row per resource with a `capacity` column;
its overlap check sums booked quantities within the requested window and is
enforced as an application-level guard inside the booking transaction, layered
on top of the row-level constraint.**

- Unit mode fits a room for 40 modelled as 40 individually bookable seats, a
  device pool, or anything ADR-0020's original design already covers: each
  unit is its own resource row, each booking claims exactly one, and two
  bookings of the same unit are rejected by the exclusion constraint with no
  additional code.
- Quantity mode fits ADR-0005's own booth example — "booth B12, 12 m²" is a
  measured capacity, not 12 interchangeable one-square-metre units — and
  anything else sold by amount rather than by discrete slot. The resource is
  one row carrying a `capacity` value; a booking claims a quantity within
  that capacity for its interval.
- The quantity-mode overlap check is: for the booking's proposed interval,
  sum the quantities of all existing bookings on that resource whose
  intervals overlap it, and reject if the sum plus the new booking's
  quantity exceeds `capacity`. A PostgreSQL exclusion constraint expresses
  "these two rows must not overlap," not "the sum of these overlapping rows
  must not exceed N" — there is no native range-aggregate constraint for
  that, so the sum check runs inside the Action's `DB::transaction`, under a
  row lock on the resource, immediately before the booking insert.
- The row-level exclusion constraint still applies underneath quantity mode
  on `(resource_id, interval)` uniqueness where relevant, but it is not what
  is carrying the capacity invariant — the application-level sum check is.

### Alternatives considered

**Materialize N unit rows for quantity resources instead.** Reuses
ADR-0020's constraint untouched, no new invariant class to design. Rejected:
it forces every quantity resource into an arbitrary discrete grain (is 12 m²
twelve one-metre units, or one? does a booking need to claim a contiguous
run of them?) purely to satisfy the constraint's shape, which loses the
actual capacity number as a first-class value and reintroduces the "fixed
slot grid" problem ADR-0020 already rejected for time, now for space.

**A database-level range-sum constraint for quantity mode.** Would keep the
invariant enforced entirely in the persistence layer, matching ADR-0020's
own reasoning for rejecting application-level checks on unit-mode bookings
almost word for word — "a raw insert, a data fix or a second write path
breaks it silently." Rejected for release: PostgreSQL has no native
construct for "sum of quantities across overlapping ranges must not exceed
N" the way `EXCLUDE USING gist` expresses plain non-overlap; building one
means a custom aggregate, trigger, or materialized-window scheme with no
prior art in this codebase, which is disproportionate engineering for a
capacity mode used by exhibitor booths and rooms, not the payment-bearing
seat inventory ADR-0020 was built to protect at all costs.

## Consequences

- **Quantity mode's invariant is weaker than unit mode's, and that is a
  direct, acknowledged contradiction of ADR-0020's own stated reason for
  rejecting application-level checks in the first place.** A raw insert, a
  data-fix script, or a second write path that bypasses the guarded Action
  can overbook a quantity resource silently — exactly the failure class
  ADR-0020 called "the exact class of bug the SPEC asks to have prevented in
  the persistence layer." This ADR accepts that gap for quantity mode
  because no clean database-level equivalent exists, not because the
  objection stopped applying.
- The row lock the application guard takes must cover the whole overlap
  window, not just the resource row, or two concurrent bookings can both
  read "capacity available" before either commits — a straightforward
  transaction-isolation bug if the guard is written as a naive
  read-then-write.
- Two capacity modes mean two code paths in every consumer that books a
  resource: the booking Action must branch on `ResourceType`'s mode, and any
  future "which resources are free right now" query needs a different query
  shape per mode (row existence versus a summed-quantity comparison).
- If quantity-mode overbooking turns out to matter in practice, the fallback
  named in the alternatives — a bespoke database-level aggregate constraint
  — is the next place to look, not a redesign of the mode split itself.

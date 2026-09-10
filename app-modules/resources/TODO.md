# Resources — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next

- [ ] `ReleaseBookingsOnRegistrationCancelled` and
      `ReleaseBookingsOnRegistrationRejected` (Seating Task 6).
- [ ] `resources:expire-holds` every minute, with the last-run heartbeat the
      S6 gauge reads (Seating Task 7).
- [ ] Seating, per `docs/superpowers/specs/2026-09-10-seating-design.md`:
      plans, document and projection with ADR-0060 (S1); save pipeline and
      conflicts (S2); seat claims and picking (S3); console (S4); picker
      (S5); observability, GDPR handler and the SPEC merge (S6).
- [ ] Allocation beyond first-fit: a claim takes the first free resource of
      its type, which is honest and reversible but not what an organizer
      means by "put Musterfirma near the entrance". The seat picker (S3/S5)
      covers seats; booths still first-fit.
- [ ] `EventRescheduled` must also *shift* existing whole-event bookings
      (ADR-0020 assigns Resources that job, #28); today it only re-projects
      the window. Out of scope for the seating slices (design doc §14).

## Backlog

- [ ] `resources:probe-seat-race` (design doc §11, optional): child
      processes on the `commerce:probe-invoice-numbering` pattern proving one
      winner for N parallel picks.

## Done

- [x] Seating Task 5 — `FulfilResourceClaims` is idempotent per
      `(registration_id, amendment_id)`, books `n` separate unit resources
      for quantity `n` in natural label order (`A-2` before `A-10`), and
      catches only `ResourceUnavailableException`. New
      `FulfilClaimsOnRegistrationAmended`; both fulfilment listeners gate
      on the payload's brand (`moduleActiveForBrand`) so the webhook path
      fulfils too.

- [x] Seating Task 4 — `HoldResourceBooking` refuses `quantity > 1` in unit
      mode (`InvalidArgumentException`, a programming error rather than an
      availability outcome), accepts `claimId`, `amendmentId` and `label`
      and snapshots the resource label; a type with `hold_minutes` 0 holds
      without a clock. `ResourceBookingHeld/Confirmed/Released` name the
      event, the type, the registration, the claim, the label and (from S1)
      the ref, so consumers never join into this module's tables.

- [x] Seating Task 3 — `resource_event_windows` carries `name` and `slug`;
      `ProjectEventWindow` is one upsert on `(brand_id, event_id)`, parses
      the wall-clock payload with its zone (ADR-0027) and listens to
      `EventDetailsUpdated` too. `resource_bookings` gained `claim_id`
      (FK arrives with `resource_claims` in S3), `amendment_id` and `label`.
      `ResourceFactory` defaults to unpublished like the migration and has a
      `published()` state. `tests/Helpers.php` with the `seating*` helpers.

- [x] Shipped in slice 8 as five cases; "manage availability" and "book own"
      are not cases (availability is a booking, and booking one's own needs
      no capability); `ReleaseBookings` was added instead.

- [x] Slice 8 (domain) — bookings, capacity modes and claim fulfilment.
      `resource_types` / `resources` / `resource_bookings` with ADR-0020's
      half-open `[starts_at, ends_at)` invariant as a **PostgreSQL exclusion
      constraint**, not a check in an Action — a read-then-write check loses
      to a race it cannot see, which is the ADR's whole argument. First
      `CREATE EXTENSION` in the repo (`btree_gist`); the project is now
      PostgreSQL-specific, which ADR-0020 names and accepts. The constraint
      is partial over `state IN (held, confirmed) AND is_exclusive`:
      releasing frees the slot without deleting who held it, and
      quantity-mode bookings are excluded because their invariant is a
      *sum* no constraint can express (ADR-0034) — they are guarded by a
      locked, summed check in the Action instead.
      Hold → confirm → release Actions emitting `ResourceBookingHeld` /
      `Confirmed` / `Released`; only `Confirmed` is the contract event.
      `resource_claim` grants materialized; fulfilment fails soft by design.
      `ResourceEventWindow` projects event times from `EventCreated` /
      `EventRescheduled`, because a whole-event claim needs an interval and
      Resources may not read Event's models (ADR-0004).

- [x] Write `SPEC.md` — purpose, ownership boundary, event contract.

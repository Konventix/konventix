# Resources — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next

- [ ] Resource + resource-type admin UI, and the seat-plan surface (Vue plan
      component + Platform CMS block type). The domain is complete and
      testable; there is no screen, so resources are created via factories or
      tinker today.
- [ ] Scheduled hold-expiry sweep: holds carry `hold_expires_at` and
      confirmation refuses an expired one, but nothing yet moves them to
      `expired` in the background, so a lapsed hold keeps its slot until
      someone tries to confirm it.
- [ ] Allocation beyond first-fit: a claim takes the first free resource of
      its type, which is honest and reversible but not what an organizer
      means by "put Musterfirma near the entrance".
- [ ] `EventRescheduled` must also *shift* existing whole-event bookings
      (ADR-0020 assigns Resources that job); today it only re-projects the
      window, so bookings made before a reschedule keep the old interval.
- [ ] Decide how the no-overlap invariant is enforced (PostgreSQL exclusion
      constraint over a range type versus an application-level guard that also
      works on the SQLite test database) and record the outcome — it shapes both
      the migrations and the booking Actions.
- [ ] Add migrations for `resource_types`, `resources`, `resource_availabilities`,
      and `resource_bookings`: ULID primary keys, `BelongsToBrand`, `ulid`
      columns without FK constraints for `event_id`, `venue_id`, and the polymorphic
      holder reference (ADR-0014).

## Backlog

- [ ] Capacity modes per ADR-0034 (decision log Round 6, D-EVT-03): add a
      `mode` column (`unit` / `quantity`) to `ResourceType`; `unit` resources
      get one row per bookable unit and the exclusion constraint applies
      directly, `quantity` resources carry a capacity column and need a
      summed-overlap guard in the booking Action since no single database
      constraint expresses an aggregate check.
- [ ] Implement the booking Actions — hold, confirm, release, expire, transfer —
      each with `DB::transaction` and the chosen locking strategy inside, and
      emit the events listed in `SPEC.md`.
- [ ] Implement claim fulfilment: a listener on Registration's confirmation and
      cancellation events that materializes and releases bookings idempotently,
      short-circuiting when the `resources` feature is off for the brand.
- [ ] Build the seat-plan surface: one `Data` object for the plan and its
      availability, a Vue plan component under ~300 lines, and a Platform CMS
      block type so brands can embed the plan on a public page.
- [ ] Add a scheduled job that expires stale holds, plus `src/Gdpr/` export and
      holder detachment wired to Compliance's erasure fan-out.
- [ ] Feature tests for the Phase 2 definition of done: seat booking for an SXLan
      registration and booth allocation for an invoice-settled exhibitor
      registration, including a concurrent double-booking test.

## Done

- [x] Shipped in slice 8 as five cases; "manage availability" and "book own"
      are not cases (availability is a booking, and booking one's own needs
      no capability); `ReleaseBookings` was added instead.

- [x] Slice 8 (domain) — bookings, capacity modes and claim fulfilment.
      `resource_types` / `resources` / `resource_bookings` with ADR-0020's
      half-open `[starts_at, ends_at)` invariant as a **PostgreSQL exclusion
      constraint**, not a check in an Action — a read-then-write check loses
      to a race it cannot see, which is the ADR's whole argument. First
      `CREATE EXTENSION` in the repo (`btree_gist`); the project is now
      PostgreSQL-specific, which ADR-0020 names and accepts. The test suite
      already ran on PostgreSQL, so that half of the cost was paid earlier.
      The constraint is partial over `state IN (held, confirmed) AND
      is_exclusive`: releasing frees the slot without deleting who held it,
      and quantity-mode bookings are excluded because their invariant is a
      *sum* no constraint can express (ADR-0034) — they are guarded by a
      locked, summed check in the Action instead. `is_exclusive` is
      denormalized from the type's mode because a constraint sees only its
      own row.
      Hold → confirm → release Actions emitting `ResourceBookingHeld` /
      `Confirmed` / `Released`; only `Confirmed` is the contract event, since
      "a hold is provisional and must never reach a badge".
      `resource_claim` grants are finally materialized: before this they were
      silently dropped, because Admission skips any grant that is not an
      admission and nothing else listened. Fulfilment fails soft by design —
      an unbookable claim leaves the registration intact.
      `ResourceEventWindow` projects event times from `EventCreated` /
      `EventRescheduled`, because a whole-event claim needs an interval and
      Resources may not read Event's models (ADR-0004).

<!-- Move completed items here with the PR that finished them. -->

- [x] Write `SPEC.md` — purpose, ownership boundary, event contract.

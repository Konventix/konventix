# Resources — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next

- [ ] Decide how the no-overlap invariant is enforced (PostgreSQL exclusion
      constraint over a range type versus an application-level guard that also
      works on the SQLite test database) and record the outcome — it shapes both
      the migrations and the booking Actions.
- [ ] Add migrations for `resource_types`, `resources`, `resource_availabilities`,
      and `resource_bookings`: ULID primary keys, `BelongsToBrand`, `ulid`
      columns without FK constraints for `event_id`, `venue_id`, and the polymorphic
      holder reference (ADR-0014).
- [ ] Define the capability catalog in `src/Enums/Permission.php` (manage types,
      manage resources, manage availability, book for others, book own, view
      bookings) and make `permissions:sync` pick it up (ADR-0013).

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

<!-- Move completed items here with the PR that finished them. -->

- [x] Write `SPEC.md` — purpose, ownership boundary, event contract.

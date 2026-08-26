# Staffing — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next

- [ ] Add migrations for `teams`, `shift_roles`, `shifts`, `assignments`, and
      `staffer_profiles`: ULID primary keys, `BelongsToBrand`, and `ulid`
      columns without FK constraints for `event_id` and `user_id` (ADR-0014).
- [ ] Build the `StafferProfile` projector — listeners on Identity's user
      events, idempotent and replayable — plus a backfill command for brands
      that switch the feature on after the fact.

## Backlog

- [ ] Implement the assignment state machine as Actions (offer, accept, decline,
      release, complete, mark no-show) with `DB::transaction` inside, and emit
      the events listed in `SPEC.md`.
- [ ] `AssignmentAccepted` → crew Admission event contract (decision log
      Round 6, D-STF-01): Staffing emits `AssignmentAccepted` on acceptance;
      pair it with an Admission-side listener (tracked in Admission's own
      `TODO.md` too) that issues the crew Admission. Staffing never writes to
      Admission directly — the coupling is the event, one direction only.
- [ ] Per-team approval opt-in (D-STF-01): a team-level flag that routes new
      sign-ups through `AssignmentOffered` before `AssignmentAccepted` instead
      of straight to self-service acceptance.
- [ ] Implement shift coverage as a single query over demand per shift role
      versus accepted assignments; no per-shift lookups (`shouldBeStrict()` is
      on in non-production).
- [ ] Build the two Vue surfaces: the lead-facing roster board and the
      staffer-facing "my shifts" page, each backed by one `Data` object and
      Wayfinder route helpers (ADR-0009); register nav entries with Platform.
- [ ] Add `src/Gdpr/` export and anonymization for `StafferProfile` and
      assignment history, wired to Compliance's erasure fan-out.
- [ ] Feature tests for the Phase 2 definition of done: an SXLan shift plan
      staffed end to end, plus a test proving every listener short-circuits when
      the `staffing` feature is off for the brand (ADR-0003).

## Done

<!-- Move completed items here with the PR that finished them. -->

- [x] Shipped in slice 9, matching this list 1:1 (`ManageAssignments` =
      assign others).

- [x] Write `SPEC.md` — purpose, ownership boundary, event contract.
- [x] `TeamFactory` and `ShiftRoleFactory` no longer wrap a four-item
      `randomElement()` in `unique()`: it ran out after four rows and threw,
      which is fewer than one shift plan needs. Uniqueness now comes from the
      suffix, which is what it was always doing the work of. Found by the demo
      seed, which builds several shifts per event.

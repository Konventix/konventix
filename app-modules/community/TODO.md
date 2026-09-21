# Community — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

**Pulled forward by slice 25.** Community was a Phase 3 module; ADR-0051 and
ADR-0052 brought the profile read models, `u/{username}` and its wall into
release scope. Chat, presence and achievements stay Phase 3 (`docs/ROADMAP.md`),
and the backlog below is still a starting point for that phase.

## Next

- [ ] Slice 26 (galleries) is the next Community work in `docs/ROADMAP.md`;
      groups and clans come after it. Nothing else scheduled.

## Backlog (not before Phase 3)

- [ ] Settle chat transport (polling, websockets, external service) — it changes
      the deployment surface and deserves an ADR.
- [ ] Decide how community read models are backfilled when a brand enables the
      feature late.
- [ ] Define the capability catalog in `src/Enums/Permission.php`, including a
      moderation dimension.
- [ ] Chat messages and awards join `CommunityGdprHandler` when those shapes
      exist — they are personal data, and their retention has to be answered
      together with Compliance.
- [ ] First migrations: brand-scoped (not event-scoped), ULID primary keys
      (ADR-0014).

## Done

- [x] Wall comments link the author's `@username` to their profile
      (ADR-0065, bugfix round 2026-09-21 Task 17a) — Wayfinder's
      `community.profile.show`, since this page only exists where the module
      is active; whether the linked page answers is the profile's own
      visibility rule (ADR-0052).
- [x] The wall comment is the handle and nothing else (ADR-0065, D-IDP-15,
      round 4 Task 22): `ProfileController::presentComments()` no longer
      ships `authorName` — `authorsById()` reads the handle column alone —
      and `Profile.vue` renders the linked `@username`, falling back to
      "A member" for an author without one (a tombstoned or not-yet-replayed
      row). The profile heading above keeps display name and handle: that is
      the subject's own page (ADR-0051 §7).
- [x] Write `SPEC.md` — purpose, ownership boundary, event contract.
- [x] `src/Gdpr/CommunityGdprHandler` (slice 25 tail, ADR-0051 §9): exports the
      profile read model, brand memberships, authored comments, reactions and
      event participations; deletes in a fixed order — the subject's own
      reactions first with a recount of the comments they sat on, then the
      comments they authored after the reactions other people left on those,
      then participations, membership and the profile row — so no reaction
      dangles and no `reaction_count` goes stale. No-op both ways for an
      email-only subject: Community holds nothing keyed by a bare address.
      `tests/CommunityGdprTest.php`; Compliance's `GdprSweepTest` runs it in
      the fan-out.

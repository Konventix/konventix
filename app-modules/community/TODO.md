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
- [ ] A shoutbox room is closed only by `EventConcluded` while `community` is
      on for the brand (ADR-0003's listener gate), so a brand that switches
      the module off between opening its wall and concluding the event keeps
      that room open and outside the 30-day sweep. `docs/operations.md`
      ("Shoutbox") has the manual repair (a tinker line, since
      `event:replay-details --with-state` replays only the current lifecycle
      event and so cannot reach an archived event's conclusion); decide whether the close belongs
      outside the gate, since it builds no read model and only starts a
      retention clock on rows that already exist.

## Backlog (not before Phase 3)

- [ ] Decide how community read models are backfilled when a brand enables the
      feature late.
- [ ] Awards join `CommunityGdprHandler` when that shape exists — personal
      data, and its retention has to be answered together with Compliance.

## Done

- [x] Shoutbox retention, gating, GDPR and observability (0.9.0 sub-project D
      Task 6, design §7 and §12 items 7-10, ADR-0059, ADR-0067).
      `CloseRoomOnEventClosed` sets `closed_at` from the conclusion or
      cancellation instant (gated, idempotent); `community:sweep-shoutbox` runs daily without
      overlapping and deletes a room's messages, reports and mutes 30 days
      later, keeping the room tombstone. `CommunityGdprHandler` exports the
      subject's lines, reports, mutes and admission badges; erasure deletes
      their lines (and the reports on them), anonymises the reports they filed
      (reporter and note), nulls their ULID in every moderator column on
      other rows, and deletes their mutes and badges. Metrics
      `community_messages_posted_total{brand}` and
      `community_shoutbox_broadcasts_total{brand,outcome}`, panels on the
      Platform dashboard, alert `KonventixShoutboxBroadcastFailing` — the
      broadcast moved from the framework's `ShouldRescue` into
      `Listeners\BroadcastToRoom` so a failure is counted, not only reported.
      The report's resolve triple left `$fillable`. `SPEC.md` amended per
      ADR-0067; the chat-transport, retention and presence questions closed.
      `tests/ShoutboxRetentionTest.php`, `tests/ShoutboxActivationTest.php`.
- [x] Capability catalog: `Permission::ModerateCommunity` (shoutbox Task 3),
      held by the desk and granted without admission (D-COM-04).
- [x] First shoutbox migrations (Task 1): brand-owned, ULID keys, rooms
      carrying an event subject (ADR-0067 decision 1).
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

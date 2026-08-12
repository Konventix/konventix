# Competition — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next

- [ ] Add migrations for `competitions`, `entrants`, `entrant_members`,
      `matches`, and `participant_profiles`: ULID primary keys,
      `BelongsToBrand`, and `ulid` columns without FK constraints for
      `event_id`, `game_id`, and `user_id` (ADR-0014).
- [ ] Define the capability catalog in `src/Enums/Permission.php` (manage
      competitions, manage entrants, manage schedule, record results, enter a
      competition, view entrant contact data) and make `permissions:sync` pick
      it up (ADR-0013).
- [ ] Emit `MatchScheduled` and `MatchFinished` exactly as ADR-0012 specifies —
      ULIDs and scalars only — since GameOps is built against them. Add an arch
      test proving Competition imports no GameOps class.

## Backlog

- [ ] Build the signup flow: entrant creation and roster management as Actions,
      eligibility checked against the admission read model, quota enforced
      against the competition's participant limit.
- [ ] Implement the LanBrackets webhook endpoint behind the module gate:
      signature verification, idempotent upserts of externally created matches,
      and a stable mapping from external match identifiers to local ULIDs.
- [ ] Consume GameOps' `MatchSessionLive` and the proposed `MatchResultReported`
      and `MatchSessionFailed`; per decision log Round 6 (D-CMP-11) a
      handler-reported score writes result state directly and advances the
      bracket — no desk confirmation gate.
- [ ] `MatchResultCorrected` as a distinct event per decision log Round 6
      (D-CMP-12), now proposed in `SPEC.md`'s contract table: the
      tournament-desk correction Action, guarded by the result-correction
      capability, that rewrites a match's recorded score and re-derives
      standings without re-emitting `MatchFinished`.
- [ ] Solo entrants as a team of size one (decision log Round 6, D-CMP-13): the
      `Entrant` roster UI hides itself for size-1 teams rather than branching
      the entrant model.
- [ ] Build the tournament-desk and public surfaces: schedule, match detail, and
      standings, each backed by one `Data` object with generated TS types
      (ADR-0009); register nav entries with Platform.
- [ ] Add `src/Gdpr/` export and anonymization for `ParticipantProfile`, entrant
      membership, and match history.
- [ ] Feature tests for the Phase 2 definition of done: an SXLan tournament from
      signup through scheduling to recorded standings, plus a test proving every
      listener short-circuits when the `competition` feature is off.

## Done

<!-- Move completed items here with the PR that finished them. -->

- [x] Write `SPEC.md` — purpose, ownership boundary, event contract.

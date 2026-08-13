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

- [ ] Slice 10b — absorb LanBrackets' bracket engine (ADR-0019): 5 format
      engines (~3,185 LOC), the 4 contracts, the progression graph and 21 Pest
      files, renamed into `Konventix\Competition`. Dropping the REST API,
      `ApiToken` auth, webhooks, the duplicate `User` table and the
      `(source_system, external_reference_id)` mapping as the ADR directs.
      Until it lands, matches and slots must be created by hand — there is no
      seeding, no progression and no standings.
- [ ] GameOps catalogue read model (blocked on Slice 11). `Discipline.game_id`
      holds the reference today but nothing projects the catalogue fields
      D-GOP-04 names (avg match/stage minutes, signup rules, team size).
      **The contract needs settling first**: GameOps' SPEC lists no catalogue
      event at all, not even as proposed, and contradicts D-GOP-04 on scope.
- [ ] Standings. The inventory's verdict is "absorb, then persist" — the
      calculators come with 10b, the persisted model does not exist yet.
- [ ] Check-in as an Action: `Entrant::$checked_in_at` exists but nothing
      writes it. D-CMP-05 puts the admission check at check-in/match start,
      so this is where Competition records that the door verified someone.

- [ ] Build the signup flow: entrant creation and roster management as Actions,
      eligibility checked against the admission read model, quota enforced
      against the competition's participant limit.
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

- [x] Slice 10a — Competition domain (bracket engine deferred to 10b).
      `Discipline` per ADR-0024: a video game is one *kind*, and duration,
      team size and scoring hang off the discipline uniformly so no consumer
      needs a null-game fallback chain. Shipped **without** the GameOps
      catalogue read model — its producer does not exist and neither does its
      contract (GameOps' SPEC lists no catalogue event even as proposed, and
      disagrees with D-GOP-04 on the fields). ADR-0024 sanctions exactly this:
      "ship `Discipline` with exactly one kind and add the rest later".
      Per-event teams (D-CMP-14) with a solo entrant as a team of one
      (D-CMP-13), so the bracket engine never branches on whether a side is a
      person or a squad. Entry gated on a confirmed Registration and nothing
      else (D-CMP-05) — admission possession is verified later, at check-in,
      and a test asserts `EnterCompetition` never mentions Admission.
      Eligibility comes from a read model fed by *two* Registration events,
      since submission knows who and confirmation knows they are entitled.
      `MatchScheduled` / `MatchFinished` emitted exactly as ADR-0012
      specifies, plus `MatchResultCorrected`. `MatchFinished` fires **once**
      per match, guarded by `finished_event_emitted_at` rather than by state
      alone, which is what stops GameOps tearing one deployment down twice
      (D-CMP-12). Arch test proves Competition imports no GameOps class — the
      only thing keeping that dependency one-way while GameOps is unbuilt.

<!-- Move completed items here with the PR that finished them. -->

- [x] Write `SPEC.md` — purpose, ownership boundary, event contract.

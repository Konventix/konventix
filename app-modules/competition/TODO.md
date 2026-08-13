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

- [ ] Swiss pairing: decide whether to replace greedy first-fit with a
      minimum-weight perfect matching (Edmonds' blossom, 1965) as `SPEC.md`'s
      "Known limitations" describes. Not a refactor — it pairs some rounds
      differently from the absorbed engine for identical input, so it needs a
      decision. The greedy failure mode is already pinned by a test, so the
      swap has a target to beat.
- [ ] GameOps catalogue read model (blocked on Slice 11). `Discipline.game_id`
      holds the reference today but nothing projects the catalogue fields
      D-GOP-04 names (avg match/stage minutes, signup rules, team size).
      **The contract needs settling first**: GameOps' SPEC lists no catalogue
      event at all, not even as proposed, and contradicts D-GOP-04 on scope.
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

- [x] Slice 10c — standings (the inventory's "absorb, then persist"). Five
      calculators behind one contract, pure like the generators, plus a
      `competition_standings` projection and `CalculateStandings` as the only
      writer — a full recompute per stage, never a row at a time, because a
      late correction moves Buchholz for everyone who faced anyone involved.
      `PairNextSwissRound` closes the loop 10b left open: Swiss rounds after
      the first are paired off the recomputed table, with refusals for a
      non-Swiss stage, an unfinished previous round, and pairing past the
      round count.
      **Four deviations from the absorbed engine, all in `SPEC.md`:** tied
      entrants now share a placement (standard competition ranking) instead of
      being separated by seed, which was reporting a 3rd and a 4th that no
      match decided; Buchholz gives a bye a FIDE-style virtual opponent rather
      than counting real opponents only, which was quietly costing the bye
      taker a place; brackets are identified by the `bracket` column rather
      than sentinel round numbers 100/200/202; and the table is persisted
      rather than computed at query time.
      One design gap found and closed: a calculator sees finished matches
      only, so a half-played bracket looked exactly like a finished one and
      would have crowned whoever won the deepest match so far. The stage's
      `final_round` is passed in settings.

- [x] Slice 10b — bracket engine absorbed from LanBrackets (ADR-0019). Five
      formats behind one `FormatGenerator` contract, plus `Stage` and the
      `match_connections` progression graph. Dropped the REST API, `ApiToken`
      auth, webhooks, the duplicate `User` table and the
      `(source_system, external_reference_id)` mapping as the ADR directs.
      **Generators are pure functions** — `list<string>` seed order in, a
      `BracketPlan` value object out — where the predecessor's took a stage and
      mutated the database. That is what lets `BracketMathematicsTest` prove
      every correctness property without a database, and it leaves
      `GenerateBracket` as the only writer.
      Each construction is a published one and `SPEC.md` names it: standard
      recursive seeding, the circle method as a 1-factorization of `K_n`
      (Kirkman 1847), serpentine partition, the `2(k-1)` losers structure, and
      Swiss as minimum-weight perfect matching.
      **Two deliberate deviations, both documented in `SPEC.md`:** the
      predecessor's single-elimination seeding is mathematically wrong (seed 1
      versus seed 5 in an eight-bracket; its tests only ever asserted that
      seeds 1 and 2 were separated, never the pairing), so the standard
      construction replaced it; and its double elimination has no crossover at
      all, so drop-in order is now reversed on alternate rounds. Swiss pairing
      was *not* corrected — greedy first-fit is kept, its forced-rematch
      failure mode pinned by a test rather than hidden, because swapping in a
      blossom matching changes tournament behaviour and needs a decision.
      Also fixed a 10a bug this slice exposed: `competition_matches` was unique
      on `(competition_id, round, position)`, which a winners *and* losers
      round 1 position 1 both violate. Now scoped to the bracket, with
      `COALESCE` so the stage-less matches 10a created stay constrained.
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

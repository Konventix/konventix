# Competition — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next

Design settled 2026-09-18 in
`docs/superpowers/specs/2026-09-18-competition-design.md` (D-CMP-15..25,
ADR-0062). The build order for #32, each item its own merge request:

- [ ] Lifecycle and admin CRUD (D-CMP-15): `CompetitionStatus` with enforced
      transitions and `SignupClosed`; drop `competitions.format` in favour
      of the first stage; `starts_at`, `max_entrants`, description and
      rules, the check-in and result knobs; discipline CRUD; the admin
      pages behind `ManageDisciplines` / `ManageCompetitions`.
- [ ] Signup (D-CMP-17): captain `role`, `invite_code`, the interested list,
      `incomplete` entrants, `FillIncompleteEntrant` / `DeclineIncompleteEntrant`
      at close, `/my/tournaments` and join-by-code; `ParticipantProfile`
      from `UserProfileUpdated`.
- [ ] Check-in (D-CMP-18): the presence read model from Admission's events,
      `CheckInEntrant` / `UndoCheckIn`, self-service window, desk list.
- [ ] Leaderboard format (D-CMP-16): `competition_score_entries`,
      `LeaderboardSettings`, `LeaderboardStandingsCalculator`, submit and
      verify Actions; `entry_source` / `advance_count` on stages and the
      `SeedSource` seam, single stage in the UI.
- [ ] Results (D-CMP-19): `match_result_reports`, report / confirm /
      dispute / resolve Actions, proofs through the media library, the
      cascading correction that voids downstream results, and
      `competition_match_history`.
- [ ] Desk and public pages (D-CMP-20..22): seed order, generate / reset,
      per-round scheduling, the ported canvas bracket with its table view,
      event tournament index, competition, match and team pages, polling
      while `Running`.
- [ ] Capabilities and roles (D-CMP-23): `ManageSchedule`, `CheckInEntrants`,
      `ViewEntrantContacts`; Tournament Desk as a second default role.
- [ ] Metrics, dashboard row and the open-dispute alert (ADR-0059); GDPR
      export and anonymisation for the new tables; the two
      definition-of-done flows and the feature-off test.

## Backlog

- [ ] Swiss pairing: greedy first-fit stays (D-CMP-24). Replacing it with a
      minimum-weight perfect matching (Edmonds' blossom) pairs some rounds
      differently for identical input, so it needs a decision when a Swiss
      competition is actually planned. The greedy failure mode is pinned by
      a test, so the swap has a target to beat.
- [ ] Multi-stage in the UI: a second stage with `entry_source =
      previous_stage`, `PreviousStageSeedSource`, cross-group ranking of
      runners-up (D-CMP-16 modelled it; nothing builds it yet).
- [ ] Free-for-all heats as matches with N slots — a generator and a
      placement-points calculator. The leaderboard with heats covers Fall
      Guys and PUBG by hand until then.
- [ ] GameOps catalogue picker read model (ADR-0062): `competition_game_
      catalogue` from the catalogue event #179 defines. Not needed to run a
      tournament; needed to link a video-game discipline to its game.
- [ ] Consume GameOps' `MatchSessionLive`, `MatchResultReported` and
      `MatchSessionFailed` (#115); per D-CMP-11 a handler-reported score
      writes result state directly. Decide there what happens when a
      handler reports on a match the desk has already voided (§8.5 of the
      design).
- [ ] Reverb broadcasting for the desk and the public bracket; polling is
      the contract until then (D-CMP-22).
- [ ] OBS overlay with a public `share_token` route surviving brand
      resolution (ADR-0019) — with #117.

## Done

- [x] The catalogue shipped in slice 10a with six cases (`ViewCompetitions`,
      `ManageDisciplines`, `ManageCompetitions`, `ManageEntrants`,
      `RecordResults`, `CorrectResults`); the item's "manage schedule",
      "enter a competition" and "view entrant contact data" are not cases
      and are owed with the screens that need them. Tournament Admin is the
      module's default role (ADR-0045).

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

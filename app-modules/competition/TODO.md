# Competition — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next

Design settled 2026-09-18 in
`docs/superpowers/specs/2026-09-18-competition-design.md` (D-CMP-15..25,
ADR-0062). The build order for #32, each item its own merge request:

- [x] Lifecycle and admin CRUD (D-CMP-15, #296): `CompetitionStatus` with
      enforced transitions and `SignupClosed`; `competitions.format`
      dropped in favour of the first stage; `starts_at`, `max_entrants`,
      description and rules, the check-in and result knobs; discipline
      CRUD; the admin pages behind `ManageDisciplines` /
      `ManageCompetitions`. Left for the slices that own them:
      `EntrantState::Incomplete` exists but nothing moves an entrant into
      it yet (§5.4 is signup's), the scheduler tick that closes signup when
      `signup_closes_at` passes and opens it when the window arrives (§4),
      and the automatic `Finished` when the last stage's final result lands
      (§8).
- [x] Signup (D-CMP-17, #297): captain `role`, `invite_code`, the interested
      list, `incomplete` entrants, `FillIncompleteEntrant` /
      `DeclineIncompleteEntrant` at close, `/my/tournaments` and join-by-code;
      `ParticipantProfile` from `UserProfileUpdated`.
- [x] Check-in (D-CMP-18, #298): the presence read model from Admission's
      events, `CheckInEntrant` / `UndoCheckIn`, self-service window, desk
      list.
- [x] Leaderboard format (D-CMP-16, #299): `competition_score_entries`,
      `LeaderboardSettings`, `LeaderboardStandingsCalculator`,
      `SubmitScoreEntry` / `VerifyScoreEntry`; `entry_source` /
      `advance_count` on stages and the `SeedSource` seam
      (`SignupField` + `RandomSeedSource` / `ManualSeedSource`,
      `SeedStage`), plus `RegenerateBracket` — §7.5's "regeneration is
      allowed until the first result", which `GenerateBracket`'s flat
      refusal could not express. `StageFormat` is now the closed set of
      stage formats, since `FormatRegistry` holds only the five that draw a
      bracket and a leaderboard draws none. Left for the slices that own
      them: rejecting a score entry with a note and the dispute it shares a
      screen with (§8.3 is results'), the desk's verification queue and the
      seed-order drag (§9 is the desk's), the `Tournament Desk` role (§11),
      and GDPR export plus the `competition_score_entries_unverified`
      metric for the new table (§13).
- [x] Results (D-CMP-19, #300): `match_result_reports` and
      `competition_match_history`; `ReportMatchResult` / `ConfirmMatchResult`
      / `DisputeMatchResult` / `ResolveDispute`, plus `RejectScoreEntry`,
      which §8.3 owed and the leaderboard slice left here; the cascading
      correction that re-routes the progression graph and voids every
      downstream result played on the old one, with
      `finished_event_emitted_at` surviving the void so a replay emits no
      second `MatchFinished` (D-CMP-12). **Both of §8.1's knobs are now
      enforced in Actions, not only offered on the form**: `proof_policy`
      through `Proofs\ProofGate`, and `result_submission_mode`, which nothing
      had checked — a `desk_only` competition now refuses a participant score
      entry as well as a participant report.
      Left for the slices that own them: the console dialogs and the
      participant match page that drive all of this (§9, §10 — the Actions
      and the upload endpoint are here, no screen is); the `Tournament Desk`
      role and the capability checks at the routes (§11); GDPR export for
      the two new tables (erasure is done: `CompetitionGdprHandler` nulls
      every user ULID on score entries, reports and history rows and keeps
      the rows) plus `competition_disputes_open`,
      its 30-minute alert and the dashboard row (§13); `MatchCancelled` and
      `MatchRescheduled` as events — `ScheduleMatch` writes the history rows
      but emits neither, and a match cancellation has no Action at all.
- [ ] **Owed by Platform before proofs ship (design §8.7).** The media
      library takes the upload — `StoreProofImage` hands the file to
      `StoreMediaUpload` (ADR-0035) after checking the policy and the roster,
      so a `none` competition refuses before a byte is stored. What it does
      **not** have is the read half §8.7 asks for: a private disk and
      per-object read authorization, "readable only by the two sides and the
      desk through a proxied route". `MediaFileController` is deliberately
      unauthenticated and brand-agnostic, and there is no per-item privacy
      flag. Until Platform grows one, a proof is readable by anyone holding
      its ULID. §8.7 is explicit that this is a Platform change and not a
      Competition workaround; recorded in the design's §14. The upload route
      (`competition.proofs.store`) is **live ahead of it**, behind `auth` and
      `throttle:competition-participant`; the participant screens must not offer an upload
      control until the read half lands.
- [x] Tournament desk (D-CMP-20, D-CMP-21, #301 first half):
      `…/{competition}/desk` with the open disputes and their resolve dialog,
      the seed-order drag and shuffle, generate / draw again / reset
      (`ResetStage`, which `RegenerateBracket` now composes), the matches
      round by round with per-round "schedule all at" (`ScheduleRound`) and
      per-match times, record (`RecordResultAtDesk`) / correct / resolve
      dialogs, the standings, and a leaderboard's verification queue with
      the desk's own verified score entry. The entrants screen gained the
      §5.2 roster powers (rename, add by person search, remove, move) and
      both it and the check-in list now use Wayfinder routes. Every console
      controller is held to the admin host's own brands
      (`ScopesToOperator`). Left: the check-in list keeps its own screen and
      the desk links to it (§14); `MatchRescheduled` is not emitted, because a
      reschedule re-emits `MatchScheduled` (#302's reconciliation); no proof
      upload anywhere on the desk (#322).
- [x] Public pages (D-CMP-22, #301 second half): LanBrackets' canvas
      bracket ported onto semantic tokens (`components/bracket/`) with the
      table view as its accessible alternative, both from one `BracketData`;
      the event tournament index, competition, match and team pages under
      `/events/{slug}/tournaments`, polled every 30 s while `Running`; the
      participant writes routed at last — enter a team, report, confirm,
      dispute, a leaderboard score — `auth` and the named
      `competition-participant` budget, the side
      always derived from the roster; "Meine Turniere" lists the reports
      waiting on the person's side and their score entries.
      `ReportMatchResult` now refuses a report while the other side is still
      to come, as the desk does. Competition keeps its own
      `competition_event_summaries` (slug and name) for the `{slug}` routes,
      backfilled by `event:replay-details`. Left: no proof upload control
      anywhere until #322 — a `required` competition says the desk collects
      the proof; the refusal causes were still English (German since #302); the
      hub cards followed.
- [x] Hub cards (§10a, D-HUB-07): *Nächste Spiele* — the viewer's open
      matches at the running event, soonest first, five at most, each with
      one nudge (Streitfall offen / Ergebnis bestätigen / Wartet auf
      Bestätigung / Jetzt dran / Ergebnis melden) from `Pages\MatchPowers`,
      the rule the match page's buttons now read too — and *Meine
      Competitions*, the viewer's entries with status and check-in. The
      card refreshes itself from `GET /live/next-games` (its own JSON, not a
      hub reload); match times on the card and the match page are on the
      event's wall clock, from the projected `timezone`. Left: a knocked-out
      entrant keeps polling until the competition ends, because there is
      no eliminated state (review M2); the `v-for` key in
      `MyCompetitionsCard.vue` (M3) and the duplicated `eventSlug()` (M4),
      both also under "Deferred review minors" below.
- [x] Linked-account signup rule (§5.6, operator decision 2026-09-22):
      `disciplines.requires_linked_provider` (None / Steam on the console
      form), checked by `EnterCompetition` on the whole roster and by
      `MaintainsTeamRoster` on every join against the contests the team
      holds a slot in, answered in German with a link to the account
      settings. Competition keeps `competition_linked_identities`,
      projected from Identity's link, unlink, announcement and merge events.
      It is global, stores no provider id, and is ungated like the other
      brand-less projections. The new `identity:announce-social-identities`
      backfills it once after the deploy (docs/operations.md). Nothing is
      retroactive, and unlinking removes nobody. Left: GameOps' catalogue
      event may suggest the value one day (ADR-0062); check-in does not ask
      for the link.
- [x] Capabilities, observability, GDPR and the definition of done (#302):
      `ViewEntrantContacts` and the **Tournament Desk** default role beside
      Tournament Admin (§11); the console honours the module gate through
      `ScopesToOperator`, and `CompetitionActivationTest` proves both hosts go
      quiet with the module off; §12 reconciled against the code (design §14),
      which changed `MatchScheduled` to wait for every side and to be
      re-announced when the bracket fills a timed match or a correction voids
      one; every refusal a participant can reach answers in German
      (`participantMessage()`), the join form's single sentence included; the
      four §13 metrics, the dashboard's *Tournaments* row and
      `KonventixCompetitionDisputeWaiting`; GDPR export of reports, score
      entries and history rows, and erasure on the eligibility read model; the
      two definition-of-done flows (a 2v2 CS cup, a Trackmania leaderboard),
      which found that a leaderboard competition could never start.

## Owed

Found on the way and not built. Each one is still true as of #302.

- [x] **The signup scheduler tick** (§4, final review fix wave B):
      `competition:signup-tick` every minute closes a `Published`
      competition once `signup_closes_at` passes (`CloseSignup`) and
      announces `SignupOpened` once per window (`AnnounceSignupOpened`,
      `competitions.signup_opened_at`), gated per brand (design §14).
- [x] **Eligibility unlearns a refund** (final review fix wave B, part 2,
      I1): `RegistrationCancelled` and `RegistrationRejected` clear the
      confirmation, and `registration:announce-standing` is the backfill for
      registrations written without events (design §14).
- [ ] **`CompetitionUnpublished`** before Agenda's competition half ships
      (design §14; Agenda's `TODO.md` names it as its precondition).
- [x] **Two notices §12 names** (fix round): `competition.match-disputed`
      to the other side of a dispute, `competition.checked-in` to the roster.
- [ ] **A retraction for `MatchScheduled`** before GameOps consumes it:
      redraws, a correction that empties a slot and a re-announced void all
      go unannounced today. GameOps 0.10 (#179) adds `MatchCancelled`
      (design §14, "`MatchScheduled` has no retraction yet").
- [ ] **A contact view** behind `ViewEntrantContacts`, once a read model
      carries contact data (design §14).
- [ ] **The admin navigation's module gate**: the console entries stay
      visible with the module off everywhere and lead to empty lists. Hiding
      them is a Platform change to admin navigation (design §14).
- [ ] **Door presence after a mid-event enable**: `AdmissionOwnershipAnnounced`
      carries no scan time, so `checked_in_at` cannot be backfilled. Until
      Admission adds a backfill that carries the first `in` scan, operators
      leave `requires_event_check_in` off (docs/operations.md).
- [ ] **Multi-stage preconditions** (design §14): when a stage counts as
      complete, the tie policy at the advancement cut, and seeds per stage
      rather than on `entrants.seed`.
- [ ] **Free text on erasure**: a note that names its own author survives
      the author's erasure (design §14).
- [x] Final review fix wave B, the rest (design §14): participant writes
      refuse a draft competition or a non-public event; the proof upload,
      interest and score bounds (part 2, M3–M5); savepoints around the
      best-effort writes (M2); the GDPR export keeps a submitter's note to
      the submitter (M1); refusals shown on the list and on withdraw;
      Wayfinder routes on *Meine Turniere* and the join page; one term per
      thing (*Streitfall*, *Turnierleitung*), a German default unit and a
      decimal comma; the readers ask the Actions' current-side rule; a
      confirm is offered during a dispute; the hub card's timer stops on
      unmount.
- [x] Destructive clicks ask first on the dialog primitive (final review
      fix wave B, part 3, I2): a participant's withdraw and leave; the
      console's decline, withdraw and disqualify, *Cancel competition* and
      *Finish*, and the desk's redraw and reset (no `window.confirm` left).
- [x] Every time on the tournament pages is on the event's wall clock
      (§10a, final review fix wave B): the schedule, the bracket table, the
      team page, both start times and *Meine Turniere*'s check-in time.
- [ ] A knocked-out entrant keeps polling *Nächste Spiele* until the
      competition ends: there is no eliminated state.
- [x] **Final review, fix wave A** (bracket and result correctness): every
      settle and correction path checks the winner and refuses an impossible
      draw; a disqualification never dead-ends the bracket; the match is
      re-read under the lock on every write; signup does not reopen over a
      draw and `start` refuses a drifted field; settling a disputed match
      closes the dispute; a leaderboard's non-scorer shows no value; a
      correction drops the claims of a re-paired match; erasing a captain
      hands the team on (design §14, the fix-wave entries).
- [ ] A disqualified entrant's emptied slot is a bye the desk settles by
      hand once the opponent arrives, like every bye (design §14); nothing
      prompts the desk for it. And a later correction that makes a
      disqualified entrant the winner of a match it lost routes it on again:
      `AdvanceBracket` does not look at entrant states.
- [ ] **M-h: a correction that voids a match with no winner does not re-open
      the bye-settled match below it.** A match with no winner (a closed empty
      match, or a lone disqualification forfeit) feeds nobody, so when a
      correction voids it `reroute()` touches nothing downstream — but the
      match below may already have been settled as a bye on the strength of
      that finished feeder, and it stays finished. When the voided match is
      replayed, `AdvanceBracket` writes its winner into the *finished* match
      below (walk: SE5 with two disqualifications, then the desk corrects r1 p2
      to s5; r2 p1 is voided, and its winner lands in the finished final's
      slot 1). Needs a disqualification plus a correction; without the round-2
      closing the same brackets dead-ended instead. Fix: after a void, add the
      edge targets that are finished and whose fed slot is empty to the
      cascade's touched set, or have `AdvanceBracket` refuse to fill a finished
      match (final review, fix wave A round 3).
- [ ] **The signup tick's close does not re-check `signup_closes_at` under the
      lock**: `SignupWindowTickCommand::dueToClose()` reads the date outside
      the lock, and `TransitionsCompetitionStatus::transition()` re-checks
      only that `Published → SignupClosed` is legal, not the date itself — a
      window reopened between the query and the lock still closes.
- [ ] **Withdraw and check-in gate on the event but not on a `Draft`
      competition**: `WithdrawEntrant` and `CheckInEntrant` refuse `Running`
      or a terminal state respectively, and neither refuses a competition
      still in `Draft`.
- [ ] **The score bound `Between(±999999999)` accepts values that round past
      `decimal(12,3)`**: `ScoreEntryData::MAX_VALUE` bounds the whole part
      only; a float that carries more than three fractional digits at the
      boundary can still round the column past its 12-digit capacity.
- [ ] **`withoutOverlapping()` keeps the default 24 h lock**: a killed
      `competition:signup-tick` run leaves the schedule's lock standing, so
      the next minute's run is skipped until it expires — signup can stall
      closed or unannounced for up to a day.
- [ ] **The signup tick has no metric**: `competition:signup-tick` closes and
      announces windows unobserved; `CompetitionMetrics` has nothing for it
      (`.ai/rules/observability.md`).
- [ ] **`registration:announce-standing` has a small replay race with a live
      confirmation**: a `RegistrationConfirmed` landing mid-replay can
      interleave with the backfill's own write to
      `competition_eligible_registrations`.
- [ ] **The console desk times and `MatchHistory` still show the reader's
      time zone**: only the hub card and the public tournament pages read the
      event's wall clock so far (§10a, ADR-0027).
- [ ] Deferred review minors, each small: the invite-code retry loop cannot
      retry under Postgres (a unique violation aborts the transaction) though
      its docblock says it does; `ExpressInterest::enteredTeamOf()` is N+1;
      `my/Tournaments.vue` declares a `brandId` prop it never uses; decline
      is possible before signup closes; `ScheduleRound` silently does nothing
      for a round with no open match; the default time on the desk's round
      form is set for the first round only; the console scope test walks a hand-written route
      list; a replayed `AdmissionIssued` out of order can null an existing
      holder; the deferred `afterCommit()` dispatches do not restore the
      brand context a later listener might read; the console's person
      search finds only people with a `ParticipantProfile`, and the issue
      for it is still owed (Task 6); the two "first operator" rules can
      diverge (Task 6 M-6); `FillIncompleteEntrant` names only the first
      unlinked person in its refusal (7c M4); the discipline is loaded twice
      per page view (7c M6); the `v-for` key in `MyCompetitionsCard.vue` and
      the duplicated `eventSlug()` of the two hub cards (7b M3, M4); the
      correction cascade never leaves its stage (Task 5 M-3);
      `usePollWhile()` leaves a `Poll` in the SSR process per server render
      of a polled page (final review, part 3, M1).
- [ ] Final review minors not taken in fix wave B: `members[].userId` on
      *Meine Turniere* sends teammates' account ULIDs (part 2, M6); the
      invite code renders under `ViewCompetitions` (M7); the Tournaments
      dashboard stats `sum` a per-replica gauge (M8); German `detail` inside
      English notice bodies (M9); a re-report mails the other roster again
      (M10); profile visibility is projected and never read (M11); brand
      validation before the operator check (M12); the canvas signals some
      states by colour alone and zooms on every wheel (part 3, M5); "Alle
      Spiele in Meine Turniere" promises a list that does not exist (M7);
      the team page says "Runde n" without the section (M8); hand-written
      prop types on three pages (M9); "Meine Competitions", "Event" and the
      S / N / U column order (M6's remaining rows); a leaderboard value on
      *Meine Turniere* and the desk still prints with a decimal point.

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

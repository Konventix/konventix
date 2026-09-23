# Competition — Specification

> Stable document. Describes what this module *is*. Task tracking lives in
> `TODO.md`.

## Purpose

Competition owns the contests a brand runs at an event: the competition itself,
its entrants, its schedule, and its matches — specifically the **match result
state**. It absorbs LanCore's Competition and CompetitionSchedule domains. Two
boundaries define it. Tournament trees, seeding and pairing logic were absorbed
from LanBrackets (ADR-0019) and are module-internal; and the
machinery that actually runs a match — game servers, match handlers — belongs to
GameOps, which owns server and handler *lifecycle* state while Competition owns
*result* state (ADR-0012). What is left here is the sporting truth of the event:
who entered, who played whom, when, and what the score was.

The surfaces built on the engine — lifecycle, signup, check-in, results
reported by participants, the tournament desk and the public pages — are
specified in `docs/superpowers/specs/2026-09-18-competition-design.md`
(D-CMP-15 to D-CMP-25). This document carries the model; that one carries
the flows.

## Owns

- **Competition** — a contest under an event: its `Discipline` (ADR-0024 —
  a video game is one kind of discipline; a pinball table, a quiz format or a
  judged contest are others), participant limit, signup window, ruleset
  text, its check-in and result-submission policy, and its **lifecycle
  status** (D-CMP-15): `Draft → Published → SignupClosed → Running →
  Finished`, `Cancelled` from anything but `Finished`, transitions enforced
  by the enum (`Published → Draft` unpublishes, `SignupClosed → Published`
  reopens). The **format is not here**: it belongs to the stage (design
  §7.1), and `competitions.format` was dropped with the lifecycle. `SignupClosed` exists so an organizer can close signups and
  resolve incomplete teams before anything starts. Where the discipline is a
  video game, `Discipline` references the game by GameOps game ULID and is
  itself the source of record for team size, duration hints and draws
  (ADR-0062).
- **Entrant** — a team or a solo participant taking part, with its roster of
  members referenced by Identity user ULID (one of them `captain`), its
  seed, and its signup state — including **`incomplete`** for a roster
  below the discipline's team size, which holds a slot until the organizer
  fills it from the interested list or declines it at signup close
  (D-CMP-17). Teams join by **invite code**.
- **Interest** — a person's public "interested to play" entry on a
  competition, shown next to the entered teams so people can find each
  other, and the organizer's pool for filling incomplete teams (D-CMP-17).
- **Results, reports and disputes** (D-CMP-19, design §8) — what each side
  *claims* happened (`match_result_reports`, keyed by slot position so a
  correction that re-routes entrants cannot invalidate a claim's keys), the
  dispute two disagreeing claims open, and the desk's resolution with its
  **mandatory** note. A recorded result is changed only by a correction, and a
  correction **cascades**: downstream slots are re-routed through the
  progression graph and every downstream match that was played on the old
  result is voided. `finished_event_emitted_at` survives a void, so a replayed
  match emits no second `MatchFinished` (D-CMP-12).
- **Match history** (`competition_match_history`, design §8.6) — an
  append-only timeline per match: scheduled, rescheduled, reported, confirmed,
  disputed, resolved, recorded, corrected, voided by correction, cancelled,
  plus `checked_in` and GameOps' `session_live` / `session_failed`, which are
  reserved and written by nothing in 0.9. This is #116's "nachvollziehbare
  Historie pro Match" without the chat, shown to both sides and to the desk.
- **ParticipantProfile** — the module's read model of a person as a competitor
  (display name, tag), populated from Identity events (ADR-0004). Competition
  never reads Identity's `User` model.
- **Match** — the pairing itself and its result state: which entrants, which
  round, when it is scheduled, and once played, the score, the winner, forfeits,
  and the recorded finish. Match status here is sporting status — scheduled,
  live, finished, cancelled — never infrastructure status. Around it:
  **result reports** from the two sides (agreeing reports record the result;
  differing ones make a dispute the desk resolves with a note), optional
  **proof** images in Platform's media library, and a **match history** —
  every report, confirmation, dispute, resolution, correction and voiding as
  a row (D-CMP-19).
- **Score entries** — for a `leaderboard` stage, an entrant's submitted
  time, points or jury score, per heat where the stage has heats, counted
  only once an organizer has verified it (D-CMP-16).
- **Schedule** — when matches happen across rounds and days.
- **Standings** — the ranking derived from recorded results, persisted per
  stage as a projection that `CalculateStandings` recomputes wholesale. See
  "Standings" under "Bracket mathematics" below.
- **Brackets** — structure, seeding and pairing, absorbed from LanBrackets
  (ADR-0019). Module-internal: no external service, no webhook endpoint. A
  **Stage** is one phase of a competition (a group stage feeding a playoff is
  two stages), carries the format and its typed settings, declares where its
  entrants come from (`signup` or `previous_stage`) and how many advance,
  and `match_connections` is the progression graph between matches. The UI
  builds one stage per competition today; the model is ready for more
  (D-CMP-16). The constructions and their guarantees are documented under
  "Bracket mathematics" below.

## Does not own

| Nearby concept | Owned by |
|---|---|
| Game server provisioning, match handler configuration, `Deployment` and `MatchSession` lifecycle state | **GameOps** (ADR-0012). Competition emits `MatchScheduled` and `MatchFinished` and never provisions, configures, or tears down anything. |
| The game catalogue, its modes, and server requirements | **GameOps** owns games/modes/server requirements outright (D-GOP-04 as amended by ADR-0062). Competition's `Discipline` references a GameOps game by ULID and keeps a **picker read model** projected from GameOps' catalogue event — name, icon, engine, modes — never a query against a GameOps model. Contest defaults (durations, team size, draws) live on the discipline itself, not in the projection. |
| Proof images and their storage | **Platform** media library (ADR-0035); Competition stores the media ULID and will decide who may see it once Platform can guard a read (#322). Until then anyone holding the ULID can read a proof, which is why no participant page offers an upload (design §14). |
| User accounts, display names, avatars, Steam and other social identities | **Identity**. Competition keeps `ParticipantProfile` as a read model, plus `competition_linked_identities`, which records only *that* a provider is linked, for the signup rule (design §5.6). |
| Whether a competitor may be on site, badge, check-in | **Admission** |
| The entry fee as a commercial act, its order and invoice | **Registration** and **Commerce** (ADR-0006). Prize money and any other movement of funds never touch the platform (ADR-0007). |
| The room, LAN seats, or hardware a match is played on | **Resources** |
| Referees and tournament-desk crew, their shifts and assignments | **Staffing** |
| The public programme entry announcing the tournament | **Agenda**; brand pages and news around it, **Content** |
| Delivery of "your match starts in 10 minutes" | **Messaging** |
| Which roles may edit a result per brand | **Platform** (per-brand role data, ADR-0013) |

## Bracket mathematics

Absorbed from LanBrackets per ADR-0019. Every format here is a named,
published construction rather than an in-house heuristic, and this section
records **which** one, what it provably guarantees, and — where the shipped
code falls short of the proven algorithm — exactly where the gap is. The
guarantees below are asserted as properties in
`tests/BracketMathematicsTest.php`, which runs without a database because the
generators are pure functions from a seed-ordered `list<string>` to a
`BracketPlan`.

### Single elimination — standard (recursive) seeding

A bracket of `2^k` slots is filled by the recursively-defined permutation

```
S(1)  = [1]
S(2n) = for each s in S(n): s, then (2n + 1 - s)
```

giving `S(4) = [1,4,2,3]` and `S(8) = [1,8,4,5,2,7,3,6]`. It is the
bit-reversal permutation: slot `i` holds the seed you get by reversing the bits
of `i`. Two properties follow from the construction, which is why this is the
universal choice rather than one option among several:

1. **Every round-one pairing sums to `bracketSize + 1`.** 1 plays 16, 2 plays
   15. Each first match is as lopsided as the field allows, which is what a
   seeding is for.
2. **Seeds meet as late as the structure permits.** Seeds `i` and `j` cannot
   meet before round `k - floor(log2(max(i,j) - 1))`; 1 and 2 only in the
   final. No other arrangement delays *every* top pairing simultaneously.

**Byes fall out of the same construction.** A field of `n` plays in a bracket
of `2^ceil(log2 n)`; the absent opponents are precisely the highest seed
numbers, so byes land on the top seeds with no separate bye rule to get wrong.

### Round robin — the circle method (Kirkman, 1847)

Scheduling a round robin *is* the problem of decomposing the complete graph
`K_n` into perfect matchings — a **1-factorization**. Each round is one perfect
matching; the schedule is complete when every edge of `K_n` is used exactly
once. `K_{2m}` is 1-factorable and the circle method is the constructive proof:
fix one vertex, arrange the remaining `2m-1` in a circle, rotate.

It produces `2m-1` rounds of `m` matches, and that is **optimal** — a round can
retire at most `m` of the `C(2m,2) = m(2m-1)` edges. Odd `n` gets a virtual
entrant; whoever draws it sits out, and each real entrant takes exactly one bye
across `n` rounds.

Completeness here is structural, not tested into existence. The test asserts it
exhaustively anyway (n ∈ {2,3,4,5,6,7,8,12,15}), which is how a port earns
trust.

### Group stage — serpentine (boustrophedon) distribution

Splitting a seeded field into `g` groups fairly is a **balanced partition**.
Serpentine dealing — left-to-right, then right-to-left — gives every group one
seed from each band of `g`, so group seed-sums differ by at most `g-1`.
Sequential dealing would put seeds 1–4 in one group and 5–8 in another, which
is a sort, not a draw. Each group then plays the circle method, so within a
group every pair still meets exactly once.

### Double elimination — the `2(k-1)` losers structure

With `2^k` entrants the winners bracket is the single-elimination tree above.
The losers bracket must absorb a stream of arrivals while halving its own
field, which forces its shape: **`2(k-1)` rounds**, alternating *minor* rounds
(losers-bracket survivors halve each other) and *major* rounds (survivors meet
the fresh drop-ins). Losers from winners round `r > 1` drop into losers round
`2(r-1)`. The event needs `2n - 2` decisive matches — each of `n-1`
non-winners must lose twice — plus one if the grand final is reset.

Grand-final slot 1 is always the winners-bracket representative. The reset rule
depends on that invariant: if slot 1 wins, they have never lost and the event
is over.

### Swiss — pairing, and the honest gap

The correct formulation of a Swiss round is a **minimum-weight perfect
matching** on a graph whose edge weights punish score differences and forbid
rematches. **Edmonds' blossom algorithm (1965)** solves it exactly in
polynomial time, so an optimal pairing is computable rather than merely
approachable; FIDE's Dutch system is a rule-based specialisation of the same
idea. Round count is `ceil(log2 n)` — the rounds needed for exactly one
entrant to be able to finish undefeated.

**The shipped implementation is not that.** As absorbed, it is a *greedy
first-fit*: walk the standings-sorted list and pair each entrant with the first
later entrant they have not yet played. See "Known limitations" below.

### Deviations from the absorbed engine

ADR-0019 says absorb LanBrackets. It is not a mandate to absorb a defect, so
two behaviours differ deliberately. Both are visible in the seeding of every
event rather than hidden, and both are recorded here because they change
tournament outcomes relative to the predecessor.

| Deviation | Predecessor | Here | Why |
|---|---|---|---|
| **Single-elimination seeding** | Produces seed 1 v 5, 7 v 3, 4 v 8, 6 v 2 in an eight-bracket — pairing sums 6, 10, 12, 8 | The standard construction above — sums always `bracketSize + 1` | The predecessor's variant is simply wrong: it puts the 5 seed against the 1 seed in round one and can meet the 3 seed in a quarter-final. Its tests only assert that seeds 1 and 2 are separated, never the pairing, so the defect never surfaced. |
| **Double-elimination crossover** | None — drop-in `i` is wired to losers match `i` verbatim | Drop-in order is reversed on alternate rounds | Without a crossover, two entrants who just met in the winners bracket can be paired again immediately in the losers bracket. Every mature generator applies some reversal or rotation. |

### Known limitations

- **Swiss pairing is greedy, not optimal.** Greedy first-fit can paint itself
  into a corner: the last two entrants are a forced rematch even when a
  rematch-free pairing of the whole field existed. The code accepts the
  rematch rather than backtracking. This is asserted as a *deliberate* test
  case, not left to be discovered. Replacing it with a blossom matching is a
  change of tournament behaviour, not a refactor — it would pair some rounds
  differently from the predecessor for identical input — so it needs a
  decision, not a commit.
- **The double-elimination crossover reduces immediate rematches; it does not
  eliminate them.** Guaranteeing a rematch-free losers bracket at every field
  size is not achievable by reordering drop-ins alone.
- **The group-stage serpentine repeats a seed at each direction change**
  (A, B, B, A, A… for two groups). That is the predecessor's fill, preserved
  on purpose: a tidier loop would silently redraw every existing group.
- **Swiss plans one round at a time.** Only round one is generated up front;
  later rounds depend on results that do not exist yet. `pairNextRound()` is
  called per round, so a Swiss stage has matches but no progression graph.

### Standings

A bracket decides itself; a league does not. Round robin, group stage and
Swiss carry no progression graph, so their result *is* their standings table —
and Swiss cannot even pair its next round without one.

Calculators are pure, exactly like the generators: entrants and finished
outcomes in, ordered entries out, no database. `CalculateStandings` is the
only writer, and it recomputes the whole stage rather than adjusting rows as
results land — a correction to a match played three rounds ago changes
Buchholz for everyone who faced anyone involved, and an incremental update
chasing that would be a second, subtly different implementation of the same
rules.

| Format | Ranked on | Then by |
|---|---|---|
| Round robin | Points (`points_win` / `points_draw` / `points_loss`) | Score difference |
| Group stage | The same, once per group | Score difference |
| Swiss | Score, a draw counting a half | **Buchholz** |
| Single elimination | The round you were knocked out in | — |
| Double elimination | The **losers**-bracket round you were knocked out in | — |
| Leaderboard | The aggregate (`best` / `sum` / `average`) of verified score entries, `higher` or `lower` being better per the stage's settings | — |

**Buchholz** (1932) is the sum of an entrant's opponents' final scores. A Swiss
event never pairs everyone against everyone, so no head-to-head table exists to
consult; strength of schedule is the only information the format actually
contains. It is meaningful once the stage is complete — mid-stage it moves as
opponents keep playing, which is inherent to the measure rather than a defect
here.

**Placement uses standard competition ranking** ("1224"): entrants who cannot
be separated share a placement and the next one skips the ties. Two semifinal
losers with no third-place match are both 3rd, and nobody is 4th.

### Deviations from the absorbed engine — standings

| Deviation | Predecessor | Here | Why |
|---|---|---|---|
| **Tied placements** | Strictly increasing; every tie broken by seed, so semifinal losers were reported 3rd and 4th | Ties share a placement; seed orders the rows for display only | A seeding is a prediction made before play. Using it to award a *final* placement lets a pre-tournament guess outrank the games. A brand wanting a decided 3rd plays a third-place match, which ranks like the real match it is. |
| **Buchholz and byes** | Summed real opponents only | A bye contributes a **virtual opponent** worth the entrant's own score | Counted naively, whoever took the bye has one fewer opponent contributing and drops a place — punished for the organiser's arithmetic rather than for anything played. FIDE's virtual opponent is the standard answer. |
| **Bracket identification** | Losers and grand-final matches encoded as round numbers 100, 200 and 202 | The `bracket` column the generator already writes | Sentinel values in an integer column are swept up by any query filtering "rounds 1..n". |
| **Persistence** | Computed at query time, stored nothing | A `competition_standings` projection with `computed_at` | Defensible for a live table, wrong for a finished one: a correction months later would silently rewrite the ranking a prize was awarded on, with no record that it had changed. |

### Leaderboard — a stage without matches

A quarter of SXLAN's real programme is not head-to-head: a jury scores a
casemod, Trackmania ranks best times, Fall Guys and PUBG award points per
heat. Forcing those into a bracket is what Challonge made the organizer do.
A `leaderboard` stage (D-CMP-16) has **no matches and no progression
graph**; its truth is `competition_score_entries` — one value per entrant,
or per entrant per heat — and `LeaderboardStandingsCalculator` ranks the
verified ones with the same standard competition ranking as every other
format. Unverified entries are invisible to the calculator, which is what
makes participant submission safe.

### Known limitations — standings

- **Calculators see finished matches only**, so a half-played bracket and a
  finished one are indistinguishable from the results alone. `final_round` is
  passed in settings to close that gap; a pure call that omits it falls back to
  the deepest round played, which is right only for a completed stage.
- **Elimination standings report nothing until the deciding match is played.**
  Placement in a knockout means "where you were knocked out", and until the
  final resolves, nobody's is known.
- **All calculators are head-to-head** and skip any outcome without exactly two
  sides — a bye counts for the entrant who took it but ranks nothing else. The
  `MatchOutcome` shape carries a list of sides so a race heat or free-for-all
  needs no reshaping, but no calculator ranks one yet.
- **Group standings do not rank across groups.** Which winners advance, and how
  best runners-up compare, is a qualification question belonging to the playoff
  stage.

### How a plan becomes a bracket

Generators are pure and never touch the database. `GenerateBracket` is the only
writer: it takes the stage's field in seed order from `SignupField`, asks the
registry for the format, and persists the returned plan as matches, slots, and
`match_connections` edges. It refuses to run twice rather than orphaning
recorded results.

**Seeding is its own step** (D-CMP-20, design §7.3/§7.5). `SeedStage` asks a
`SeedSource` for the order and writes `entrants.seed`; `SeedSourceRegistry`
resolves the stage's `entry_source` to one. `RandomSeedSource` is the default
and `ManualSeedSource` carries the desk's drag order as an explicit override.
`PreviousStageSeedSource` — the one that reads the preceding stage's standings
and takes its `advance_count` — is the reason the seam exists and is not built:
adding it is one `register()` call, and no caller changes.

**Regeneration is allowed until the first result.** `ResetStage` deletes a
stage's matches, slots, edges and standings and clears `generated_at`;
`RegenerateBracket` is `ResetStage` and `GenerateBracket` in one transaction,
the desk's "draw again", and the only redraw allowed in `Running`, where a bare
reset would leave a running competition with no bracket. So a desk that spots
a wrong seeding fixes it instead of living with it. All three and `SeedStage`
refuse once any match of the stage is finished, has a winner, or carries a
score, or a leaderboard stage has a verified entry — seeds break ties in every
calculator, so renumbering after a result rewrites the table the results
produced. Regenerating does **not** re-seed: a hand-dragged order survives the
redraw.

**A leaderboard stage is never drawn.** It has no matches, so there is nothing
for `GenerateBracket` to write, and `StartCompetition` counts it as ready
without a draw.

`AdvanceBracket` walks those edges: given a finished match it moves the winner
(and, in double elimination, the loser) into the slot the graph names. It is
idempotent — re-advancing after a correction returns an empty list rather than
duplicating anyone.

Round-robin and Swiss stages carry **zero** edges. They are decided by
standings, not by progression, and the absence of a graph is the encoding of
that. `PairNextSwissRound` is the consequence: a Swiss round only becomes
knowable when the previous one finishes, so each round after the first is
paired from the recomputed standings rather than implied by a graph.

## Public contract (events)

Events are the only way other modules may react to this one (ADR-0004).
Payloads carry ULIDs and scalars — never Eloquent models. Every event is
dispatched after its transaction commits (`DB::afterCommit()`), so a refused
write announces nothing.

`MatchScheduled` and `MatchFinished` are named in ADR-0012 and are the contract
GameOps is built against. The table lists what is emitted today, with its real
payload; design §14 ("§12 reconciled") records where it departs from the
design's §12 and why.

| Event | Emitted when | Payload |
|---|---|---|
| `MatchScheduled` | A match has a time **and** every slot holds a side: when the desk times a full match, when the bracket fills the last slot of a timed match, and when a correction voids a timed match that is then on again between new sides. Never for a bye. Again on a reschedule. Consumers upsert on `match_id`. | `match_id`, `competition_id`, `event_id`, `brand_id`, `game_id`, `game_mode` (both null unless the discipline is a video game), `round`, `scheduled_at`, `entrant_ids` (slot order), `member_user_ids` (entrant ULID => roster) |
| `MatchFinished` | A result is recorded — **once per match**, even when a voided match is played again (D-CMP-12) | `match_id`, `competition_id`, `brand_id`, `winner_entrant_id`, `scores` (entrant ULID => score), `finished_at` |
| `MatchResultCorrected` (D-CMP-12) | The desk corrects a recorded result | `match_id`, `competition_id`, `brand_id`, `previous_winner_entrant_id`, `winner_entrant_id`, `scores`, `corrected_by_user_id`, `corrected_at` |
| `CompetitionPublished` (D-CMP-15) | A competition becomes publicly visible | `competition_id`, `event_id`, `discipline_id`, `brand_id`, `name`, `status`, `starts_at`, `at` |
| `CompetitionRescheduled` | An announced competition's `starts_at` moves | `competition_id`, `event_id`, `brand_id`, `previous_starts_at`, `starts_at`, `at` |
| `CompetitionStarted` / `CompetitionFinished` / `CompetitionCancelled` (D-CMP-15) | The lifecycle moves | `competition_id`, `event_id`, `discipline_id`, `brand_id`, `status`, `at` |
| `SignupOpened` / `SignupClosed` (D-CMP-15) | Publish into an open window, reopen, or the window opening (`competition:signup-tick`, once per window); the organizer closes, or the tick once `signup_closes_at` passes | `competition_id`, `event_id`, `brand_id`, `entrant_count`, `at` |
| `EntrantRegistered` | A team enters, complete or not | `entrant_id`, `competition_id`, `competition_team_id`, `event_id`, `brand_id`, `registered_at`, `member_user_ids`, `state` (`registered` or `incomplete`) |
| `EntrantWithdrawn` | An entrant is withdrawn, declined or disqualified | `entrant_id`, `competition_id`, `competition_team_id`, `event_id`, `brand_id`, `member_user_ids`, `reason`, `final_state`, `withdrawn_at` |
| `EntrantCheckedIn` | The desk or the captain checks a team in | `entrant_id`, `competition_id`, `competition_team_id`, `event_id`, `brand_id`, `member_user_ids`, `checked_in_at`, `via_self_service` |
| `MatchResultReported` / `MatchDisputed` (D-CMP-19) | A side reports; the sides disagree or one disputes | `match_id`, `competition_id`, `brand_id`, `entrant_id`, `user_id`, `at` |
| `MatchDisputeResolved` (D-CMP-19) | A dispute ends: the desk resolves it or corrects the contested result, or the sides agree after all (a confirm, an agreeing re-report) | `match_id`, `competition_id`, `brand_id`, `entrant_id` (the side the result went to, null for a draw), `user_id` (whoever ended it: the organizer, or the member of a side whose agreement settled the match), `at`. Not sent when a disqualification's forfeit ends a dispute: nobody acted on the match |

**Proposed, not emitted.** `MatchRescheduled` (`match_id`, `competition_id`,
`previous_scheduled_at`, `scheduled_at`) and `MatchCancelled` (`match_id`,
`competition_id`, `cancelled_at`, `reason`): a reschedule already re-emits
`MatchScheduled`. **`MatchScheduled` has no retraction in 0.9.0**, and three
paths need one before GameOps consumes it: a redraw (`RegenerateBracket`,
`ResetStage`) deletes announced matches without an event; a correction can
empty a slot of an announced, unplayed match; and a correction re-announces a
voided match that finishes again with no second `MatchFinished`. GameOps
0.10 (#179) owns that contract and has decided to add `MatchCancelled` (design
§14, "`MatchScheduled` has no retraction yet"). `CompetitionUnpublished`
(`competition_id`, `event_id`, `brand_id`, `unpublished_at`) is owed before
Agenda's competition half ships (design §14).

Competition's own `MatchResultReported` is **not** GameOps' proposed event of
the same name under "Consumes": same words, opposite direction. A participant's
claim waits for the other side (§8.2); a handler's reported score records
directly (D-CMP-11).

The resolution note of `MatchDisputeResolved` is deliberately **not** on the
payload. §8.4 requires it and both sides are shown it, but it is free text one
participant wrote about another, and a contract event is read by modules with
no business holding it. It lives one row away, in `competition_match_history`.

## Consumes

Events from other modules that this module listens to, and the read models it
maintains from them. Every listener is idempotent and replayable (ADR-0004).

| Event | Owning module | Why |
|---|---|---|
| `RegistrationSubmitted` / `RegistrationConfirmed` / `RegistrationCancelled` / `RegistrationRejected` / `RegistrationStandingAnnounced` | Registration | Maintains `competition_eligible_registrations`, who may enter (D-CMP-05): submit names the registrant, confirm grants, cancel and reject take it back (found by `registration_id`, outside the brand scope, since neither carries a brand), and the announcement restates a registration whole for the backfill `registration:announce-standing`. Ungated, so a brand switching the module on mid-event finds its registrations there. |
| `UserRegistered` / `UserProfileUpdated` | Identity | Maintains `ParticipantProfile` (`display_name`, `username`, `visibility`). Ungated for the same reason. `identity:replay-profiles` backfills it. |
| `SocialIdentityLinked` / `SocialIdentityUnlinked` / `SocialIdentityAnnounced` / `AccountsMerged` | Identity | Maintains `competition_linked_identities`, the providers a person has linked, which a discipline's `requires_linked_provider` is checked against on entry and on every roster join (design §5.6). Global rather than per brand, and it stores no provider account id. It is ungated, because the events carry no brand and a brand switching the module on must find the links already there. `identity:announce-social-identities` backfills it. |
| `EventCreated` / `EventDetailsUpdated` / `EventRescheduled` and the seven lifecycle events | Event | Maintains `competition_event_summaries` — slug, name, time zone and lifecycle state — which the public tournament pages resolve by and are gated on. Ungated. `event:replay-details --with-state` backfills it. |
| `AdmissionIssued` / `AdmissionHolderAssigned` / `AdmissionHolderUnassigned` / `AdmissionCheckedIn` / `AdmissionRevoked` / `AdmissionOwnershipAnnounced` | Admission | Maintains the **presence read model** — which person the door has seen at which event. Read by check-in when a competition sets `requires_event_check_in` (D-CMP-18). Never a signup gate (D-CMP-05). Gated on the brand the event names; `admission:announce-ownership` backfills it, but cannot restore a scan time. |
| `MatchSessionLive` *(proposed)* | GameOps | The server is up and the handler has started the match; Competition moves the match to live and can show connection details. It is a lifecycle signal, not a result. |
| `MatchResultReported` *(proposed)* | GameOps | The match handler observed a final score. Competition remains the owner of result state: it decides whether the reported score is recorded, held for review, or overridden. |
| `MatchSessionFailed` *(proposed)* | GameOps | Provisioning or the handler failed; the match is flagged for the tournament desk rather than silently stalling. |
| `EventArchived` *(proposed)* | Event | Freezes competitions, results, and standings as history. Heard today only as a lifecycle state on the event summary. |

## Capabilities

Declared in `src/Enums/Permission.php`. Roles that grant them are per-brand
data, not code (ADR-0013).

- `ViewCompetitions`, `ManageDisciplines`, `ManageCompetitions` (create,
  publish, close, start, finish, cancel; edit rulesets and policies).
- `ManageEntrants`: fill or decline incomplete teams, rename, add, remove and
  move members, withdraw, disqualify.
- `CheckInEntrants` (the check-in list) and `ManageSchedule` (seed order,
  generate, draw again, reset, and the times of matches and rounds) — the
  desk's day-of work (D-CMP-23).
- `RecordResults` (also verifies and rejects score entries and resolves
  disputes before a result exists) and `CorrectResults` — the capability with
  the highest blast radius, since a correction cascades through the bracket
  and standings derive from it; it also resolves a dispute raised against a
  standing result.
- `ViewEntrantContacts` is declared and gates nothing yet: the module holds no
  contact data (design §14).

Two default roles (ADR-0045), materialized when a brand activates the module:
**Tournament Admin** holds all nine; **Tournament Desk** holds
`ViewCompetitions`, `CheckInEntrants`, `RecordResults` and `ManageSchedule`,
and so runs a tournament end to end except for corrections and roster changes
(#135).

Entering a competition, joining a team by code, registering interest,
reporting, confirming or disputing a result and self-service check-in are
**not** capabilities: they follow from a confirmed registration (D-CMP-05)
and membership in a side.

## Activation

Pennant feature name: **`competition`** (the module's kebab-case name), gated
per brand (ADR-0003):

- **Routes.** Every brand-host route carries `ensure-module:competition`,
  added by the base provider. The operator console cannot carry it, because
  that host resolves no brand, so `ScopesToOperator` treats a brand with the
  module off as a stranger: its rows answer 404, the lists leave it out and
  the brand pickers do not offer it.
- **Navigation.** Competition has no brand-area entry. Its two console
  entries are filtered by capability only, as Platform's admin navigation is
  (ADR-0029).
- **UI slots.** The two hub cards are registered at boot and hidden by the
  hub registry wherever the module is off.
- **Listeners.** Door presence short-circuits on the brand the event names.
  The eligibility, name, event-summary and linked-account projections are
  ungated on purpose, so a brand switching the module on mid-event finds them
  filled (design §5.6, §14); the linked-account events carry no brand to gate
  on at all.

With the feature off a brand runs its event without tournaments: no
competitions, no signups, no schedule, no standings. GameOps becomes inert for
that brand as a consequence — every trigger it reacts to originates here — so
enabling `game-ops` without `competition` gives a brand nothing.
`CompetitionActivationTest` proves both hosts go quiet.

## Observability

ADR-0059, design §13. `src/Metrics/CompetitionMetrics.php` declares four
metrics, all labelled with the brand slug:

- `competition_entrants_total{brand,state}` and
  `competition_matches_total{brand,state}` — counters of transitions,
  recorded by `Listeners\Metrics\RecordCompetitionMetrics` on this module's
  own events. `scheduled` counts every `MatchScheduled`; `finished` counts
  each match once.
- `competition_disputes_open{brand}` and
  `competition_score_entries_unverified{brand}` — gauges answered at scrape
  time by `CompetitionGauges`, with a zero row for every brand.

They are the *Tournaments* row of the Platform dashboard.
`KonventixCompetitionDisputeWaiting` fires when a brand has had an open
dispute in a running competition for 30 minutes — the desk is told about a
dispute by nothing else (design §8.4). Runbook:
`docs/operations.md#konventixcompetitiondisputewaiting`.

## Personal data

`src/Gdpr/CompetitionGdprHandler.php` exports team memberships, interest rows
and their notes, linked providers, the result reports, score entries and
match-history rows a person wrote. On erasure it deletes the profile, the
interest rows and the linked providers, and nulls the person's ULID on every
row that must survive as the competition's history: score entries, reports,
history, door presence and eligibility. Free text on a surviving row keeps
its words (design §14).

## Open questions

- ~~Should LanBrackets be absorbed into this module later?~~ **Settled by
  ADR-0019: absorbed.** No webhook contract needed; bracket logic is
  module-internal now.
- ~~Result authority: is a handler-reported score recorded directly, or does it
  always wait for confirmation at the tournament desk? This decides whether
  `MatchResultReported` writes result state or only proposes it.~~ **Decision
  log Round 6 (D-CMP-11):** handler results record directly and advance the
  bracket; the desk holds a correction capability rather than gating every
  result.
- ~~Is there a protest or dispute workflow, and does a corrected result emit
  `MatchFinished` a second time or a distinct correction event? GameOps must
  not tear a server down twice.~~ **Decision log Round 6 (D-CMP-12):** a
  distinct `MatchResultCorrected` event; `MatchFinished` fires exactly once
  per match, so GameOps' "no double teardown" concern is satisfied by
  construction.
- ~~Are solo entrants a team of size one, or a second entrant shape? One model
  is simpler; two avoid awkward single-member rosters in the UI.~~ **Decision
  log Round 6 (D-CMP-13):** a team of size one; the roster UI hides itself for
  size-1 teams.
- ~~Do teams persist across events of the same brand? Cross-event identity is
  Community territory (post-release), so Competition should not become the
  long-lived team registry by accident.~~ **Decision log Round 7 (D-CMP-14):**
  teams are per event; cross-event identity stays Community territory, and
  `predecessor_event_id` enables a later roster-import UX.
- ~~Does entering a competition require a confirmed Admission, or only a
  Registration? The strict answer blocks signups until check-in-capable
  admissions exist, which may be too late for a schedule built in advance.~~
  **Decision log Round 6 (D-CMP-05):** confirmed Registration suffices to
  enter; admission possession is verified at check-in / match start, not at
  signup.
- ~~How are ranking and points systems configured per brand without
  reintroducing free-form settings blobs (ADR-0009)?~~ **Decision log Round 7
  (D-BATCH-7):** typed per-discipline config Data objects (ADR-0009) — no
  free-form settings blobs.
- ~~Does a handler-reported or participant-reported score need desk
  confirmation, and what happens when the sides disagree?~~ **D-CMP-19
  (2026-09-18):** two agreeing reports record the result; a differing one
  is a dispute the desk resolves with a mandatory note; score entries need
  organizer verification; corrections cascade and void downstream results.
- ~~Which catalogue fields does Competition project from GameOps?~~
  **ADR-0062:** name, icon, engine, modes — a picker. Contest defaults are
  the discipline's own.
- ~~Swiss: greedy or blossom?~~ **D-CMP-24:** Swiss stays available with
  the greedy pairing; blossom is a later decision, still pinned by its test.
- **Open:** multi-stage advancement in the UI (`PreviousStageSeedSource`,
  cross-group ranking of runners-up) — modelled (D-CMP-16), not built. Before
  it is: when a stage counts as complete, how a tie at the advancement cut is
  broken, and seeds per stage rather than per entrant (design §14).
- **Open:** free-for-all heats as matches with N slots (a generator and a
  placement-points calculator); the leaderboard with heats covers the need
  by hand until then.

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
| Proof images and their storage | **Platform** media library (ADR-0035); Competition stores the media ULID and decides who may see it. |
| User accounts, display names, avatars, Steam and other social identities | **Identity**. Competition keeps `ParticipantProfile` as a read model. |
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
writer: it resolves the stage's entrants into seed order, asks the registry for
the format, and persists the returned plan as matches, slots, and
`match_connections` edges. It refuses to regenerate a stage rather than
orphaning recorded results.

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
Payloads carry ULIDs and scalars — never Eloquent models.

`MatchScheduled` and `MatchFinished` are named in ADR-0012 and are the contract
GameOps is built against; the remaining rows are **proposed**.

| Event | Emitted when | Payload |
|---|---|---|
| `MatchScheduled` | A match's entrants and start time are fixed | `match_id`, `competition_id`, `event_id`, `game_id`, `game_mode`, `round`, `scheduled_at`, `entrant_ids` (list of ULIDs) |
| `MatchFinished` | A result is recorded and the match is closed | `match_id`, `competition_id`, `winner_entrant_id`, `scores` (scalars per entrant), `finished_at` |
| `MatchResultCorrected` *(proposed, decision log Round 6, D-CMP-12)* | The tournament desk corrects an already-recorded result | `match_id`, `competition_id`, `previous_winner_entrant_id`, `winner_entrant_id`, `scores` (scalars per entrant), `corrected_by_user_id`, `corrected_at` |
| `MatchCancelled` *(proposed)* | A scheduled match will not be played | `match_id`, `competition_id`, `cancelled_at`, `reason` |
| `MatchRescheduled` *(proposed)* | A scheduled match moves in time | `match_id`, `competition_id`, `previous_scheduled_at`, `scheduled_at` |
| `CompetitionPublished` (D-CMP-15) | A competition becomes publicly visible | `competition_id`, `event_id`, `discipline_id`, `brand_id`, `name`, `status`, `starts_at`, `at` |
| `CompetitionRescheduled` (D-CMP-15) | An announced competition's `starts_at` moves | `competition_id`, `event_id`, `brand_id`, `previous_starts_at`, `starts_at`, `at` |
| `SignupOpened` / `SignupClosed` (D-CMP-15) | Signup opens (publish into an open window, reopen, scheduler tick) or shuts | `competition_id`, `event_id`, `brand_id`, `entrant_count`, `at` |
| `EntrantRegistered` / `EntrantWithdrawn` / `EntrantCheckedIn` | An entrant joins, leaves, or is checked in | `entrant_id`, `competition_id`, `team_id`, `member_user_ids` (list of ULIDs), `reason` (withdrawn) |
| `CompetitionStarted` / `CompetitionFinished` / `CompetitionCancelled` (D-CMP-15) | The lifecycle moves | `competition_id`, `event_id`, `discipline_id`, `brand_id`, `status`, `at` |
| `MatchResultReported` / `MatchDisputed` / `MatchDisputeResolved` (D-CMP-19) | A side reports; the sides disagree; the desk decides | `match_id`, `competition_id`, `entrant_id`, `user_id`, `at` |

## Consumes

Events from other modules that this module listens to, and the read models it
maintains from them.

| Event | Owning module | Why |
|---|---|---|
| `MatchSessionLive` | GameOps | The server is up and the handler has started the match; Competition moves the match to live and can show connection details. It is a lifecycle signal, not a result. |
| `MatchResultReported` *(proposed)* | GameOps | The match handler observed a final score. Competition remains the owner of result state: it decides whether the reported score is recorded, held for review, or overridden. |
| `MatchSessionFailed` *(proposed)* | GameOps | Provisioning or the handler failed; the match is flagged for the tournament desk rather than silently stalling. |
| `UserProfileUpdated` | Identity | Maintains `ParticipantProfile` (`display_name`, `username`, `visibility`). Idempotent and replayable (ADR-0004). |
| `AdmissionHolderAssigned` / `AdmissionHolderUnassigned` / `AdmissionCheckedIn` / `AdmissionRevoked` | Admission | Maintains the **presence read model** — which person the door has seen at which event. Read by check-in when a competition sets `requires_event_check_in` (D-CMP-18). Never a signup gate (D-CMP-05). |
| `EventArchived` *(proposed)* | Event | Freezes competitions, results, and standings as history. |
| `DataExportRequested` / `UserDeletionRequested` *(proposed)* | Compliance | Fans out to `src/Gdpr/`: exports a competitor's entrant and match history, anonymizes `ParticipantProfile`, and reports completion back to Compliance. |

## Capabilities

Declared in `src/Enums/Permission.php`. Roles that grant them are per-brand
data, not code (ADR-0013).

- `ViewCompetitions`, `ManageDisciplines`, `ManageCompetitions` (create,
  publish, close, start, finish, cancel; edit rulesets and policies).
- `ManageEntrants`: fill or decline incomplete teams, move members,
  withdraw, disqualify, seed.
- `CheckInEntrants`, `ManageSchedule` — the desk's day-of work (D-CMP-23).
- `RecordResults` (also verifies score entries and resolves disputes before
  a result exists) and `CorrectResults` — the capability with the highest
  blast radius, since a correction cascades through the bracket and
  standings derive from it.
- `ViewEntrantContacts` for tournament-desk work.

Entering a competition, joining a team by code, registering interest,
reporting or confirming a result and self-service check-in are **not**
capabilities: they follow from a confirmed registration (D-CMP-05) and
membership in a side. Default role sets (ADR-0045): Tournament Admin holds
all nine; Tournament Desk holds view, check-in, record and schedule.

## Activation

Pennant feature name: **`competition`** (the module's kebab-case name), gated
per brand at all four enforcement points (ADR-0003): the route group carries
`ensure-module:competition`, Platform's nav registry filters the module's
entries, UI slots check the feature, and listeners short-circuit when the
feature is off for the event's brand context.

With the feature off a brand runs its event without tournaments: no
competitions, no signups, no schedule, no standings. GameOps becomes inert for
that brand as a consequence — every trigger it reacts to originates here — so
enabling `game-ops` without `competition` gives a brand nothing.

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
  construction. `MatchResultCorrected` still needs adding to the "Public
  contract" table above as proposed — see `TODO.md`.
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
  cross-group ranking of runners-up) — modelled (D-CMP-16), not built.
- **Open:** free-for-all heats as matches with N slots (a generator and a
  placement-points calculator); the leaderboard with heats covers the need
  by hand until then.

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

## Owns

- **Competition** — a contest under an event: its `Discipline` (ADR-0024 —
  a video game is one kind of discipline; a pinball table, a quiz format or a
  judged contest are others), team size, participant limit, signup window,
  ruleset text, and its lifecycle status. Where the discipline is a video
  game, `Discipline` references the game by GameOps game ULID (ADR-0012
  amended, D-GOP-04) rather than Competition storing `game_id` directly.
- **Entrant** — a team or a solo participant taking part, with its roster of
  members referenced by Identity user ULID, its seed, and its signup state.
- **ParticipantProfile** — the module's read model of a person as a competitor
  (display name, tag), populated from Identity events (ADR-0004). Competition
  never reads Identity's `User` model.
- **Match** — the pairing itself and its result state: which entrants, which
  round, when it is scheduled, and once played, the score, the winner, forfeits,
  and the recorded finish. Match status here is sporting status — scheduled,
  live, finished, cancelled — never infrastructure status.
- **Schedule** — when matches happen across rounds and days.
- **Standings** — the ranking derived from recorded results.
- **Brackets** — structure, seeding and pairing, absorbed from LanBrackets
  (ADR-0019). Module-internal: no external service, no webhook endpoint. A
  **Stage** is one phase of a competition (a group stage feeding a playoff is
  two stages), and `match_connections` is the progression graph between
  matches. The constructions and their guarantees are documented under
  "Bracket mathematics" below.

## Does not own

| Nearby concept | Owned by |
|---|---|
| Game server provisioning, match handler configuration, `Deployment` and `MatchSession` lifecycle state | **GameOps** (ADR-0012). Competition emits `MatchScheduled` and `MatchFinished` and never provisions, configures, or tears down anything. |
| The game catalogue, its modes, and server requirements | **GameOps** owns games/modes/server requirements outright (decision log Round 6, D-GOP-04, ADR-0012 amended). Competition's `Discipline` references a GameOps game by ULID and keeps a **read model** projected from GameOps' events carrying the fields ADR-0024 documented (avg match/stage minutes, signup rules, team size — not just name/icon) — never a query against a GameOps model. |
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
that.

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
| `CompetitionPublished` *(proposed)* | A competition becomes publicly visible | `competition_id`, `event_id`, `game_id`, `game_mode`, `max_participants`, `team_size` |
| `SignupOpened` / `SignupClosed` *(proposed)* | The signup window opens or closes | `competition_id`, `event_id`, `opened_at` / `closed_at`, `entrant_count` |
| `EntrantRegistered` / `EntrantWithdrawn` *(proposed)* | An entrant joins or leaves a competition | `entrant_id`, `competition_id`, `member_user_ids` (list of ULIDs) |

## Consumes

Events from other modules that this module listens to, and the read models it
maintains from them.

| Event | Owning module | Why |
|---|---|---|
| `MatchSessionLive` | GameOps | The server is up and the handler has started the match; Competition moves the match to live and can show connection details. It is a lifecycle signal, not a result. |
| `MatchResultReported` *(proposed)* | GameOps | The match handler observed a final score. Competition remains the owner of result state: it decides whether the reported score is recorded, held for review, or overridden. |
| `MatchSessionFailed` *(proposed)* | GameOps | Provisioning or the handler failed; the match is flagged for the tournament desk rather than silently stalling. |
| `UserRegistered` / `UserProfileUpdated` *(proposed)* | Identity | Maintains `ParticipantProfile`. The projection must be idempotent and replayable (ADR-0004). |
| `AdmissionIssued` / `AdmissionRevoked` *(proposed)* | Admission | Signup eligibility: only people admitted to the event may enter a competition, and a revoked admission flags the entrant. |
| `EventArchived` *(proposed)* | Event | Freezes competitions, results, and standings as history. |
| `DataExportRequested` / `UserDeletionRequested` *(proposed)* | Compliance | Fans out to `src/Gdpr/`: exports a competitor's entrant and match history, anonymizes `ParticipantProfile`, and reports completion back to Compliance. |

## Capabilities

Declared in `src/Enums/Permission.php`. Roles that grant them are per-brand
data, not code (ADR-0013).

- Create, publish, and cancel competitions; edit rulesets.
- Manage entrants: accept, seed, disqualify, withdraw.
- Manage the schedule: schedule, reschedule, and cancel matches.
- Record and correct match results — the capability with the highest blast
  radius, since standings derive from it.
- Enter a competition and manage one's own entrant roster — the
  participant-facing capability.
- View entrant contact data for tournament-desk work.

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

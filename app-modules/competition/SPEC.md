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
  (ADR-0019). Module-internal: no external service, no webhook endpoint.

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

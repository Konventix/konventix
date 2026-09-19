# ADR-0012: GameOps orchestration design (carried over from LanCore)

**Status:** accepted, amended (Round 6 resolutions)

> **Amended by ADR-0019.** The final Decision bullet — tournament trees/match
> logic remaining in LanBrackets, integrated via webhooks — is superseded:
> LanBrackets is absorbed into the Competition module. Everything else in this
> ADR stands unchanged.

> **Amended by ADR-0062.** D-GOP-04 below is narrowed: `Discipline` is the
> source of record for contest defaults (durations, team size, draws,
> eligibility); GameOps' catalogue event carries identity and operational
> fields only. The ownership split itself stands.

## Context

Game server provisioning and match handling for competitions. This was the one
LanCore domain built with clean event-driven boundaries — it is the template
for all Konventix modules.

## Decision

- **Ownership split:** GameOps owns server/handler lifecycle state
  (`Deployment`, `MatchSession`); Competition owns match result state.
  GameOps references `competition_match_id` as a plain value — never an
  Eloquent relation.
- **Provider abstraction:** `GameServerProvider` contract
  (provision/destroy/getStatus); first driver: Pelican Panel.
- **Handler abstraction:** `MatchHandler` contract
  (configure/start/getState/getResult); first driver: TMT2 via API client.
- **Resolvers:** `MatchHandlerResolver` picks the handler per game/type;
  `DeploymentStrategyResolver` decides server-to-match cardinality (1:1 or
  shared) per game and handler type.
- **Event flow:** Competition emits `MatchScheduled` → GameOps creates
  MatchSession, provisions, configures, starts → emits `MatchSessionLive`.
  Competition emits `MatchFinished` → GameOps tears down → emits
  `DeploymentDestroyed`.
- **Provisioning modes** per competition: Auto Before Signup / Auto During
  Signup / Auto Before Start / Static. Static pools are defined at Game level,
  scoped by GameMode. Auto pool sizing:
  `floor(max_participants / team_size / 2)` concurrent matches.
- ~~Tournament trees/match logic remain in LanBrackets (external), integrated
  via webhooks.~~ **Superseded by ADR-0019.**

## Open questions — Resolved (Round 6)

The three questions this ADR originally carried as "resolve before porting"
were resolved in the Round 6 gate review. Kept here as a record, not as open
items:

1. ~~Pool reuse between rounds.~~ **Resolved (Round 6), D-GOP-01: configurable
   per server pool.** This was the user's decision over a single fixed
   policy — the inventory's recommendation was one project-wide default, and
   it was overridden in favour of letting each pool declare its own mode.
   **Fresh-per-match is the default mode.** A pool may instead be configured
   for **reuse mode**, in which case its `MatchHandler` must implement a
   reset hook that returns the server to a clean state between matches —
   reuse mode is not available to a handler that cannot prove it can reset
   itself. `DeploymentStrategyResolver` (Decision, above) reads the pool's
   configured mode; it does not infer one.
2. ~~Fallback strategy for failed provisioning.~~ **Resolved (Round 6),
   D-GOP-02: bounded retries with backoff, then hand the match to the
   tournament desk via event.** No static-pool rescue tier exists — the
   inventory's Static provisioning mode (Decision, above) is an organizer's
   deliberate per-competition choice, not an automatic fallback a failed Auto
   deployment silently drops into. A match that exhausts its retries produces
   a signal a human acts on, never a second, unannounced sourcing strategy.
3. ~~Match session failure and heartbeat handling.~~ **Resolved (Round 6),
   D-GOP-03: provider status plus handler heartbeats, both watched.** A
   liveness-threshold breach emits `SessionUnhealthy` for the tournament
   desk. It never triggers automatic mid-match teardown — GameOps raises the
   signal, it does not decide unilaterally that a match is over. Tearing
   down a session that is merely lagging, versus one that is genuinely dead,
   is a judgement call left with the desk.

## Game catalogue ownership — Resolved (Round 6)

**D-GOP-04: GameOps owns games, modes, and server requirements. Competition's
`Discipline` (ADR-0024) references a game by ULID and keeps a name/icon read
model projected from GameOps' events — it does not read GameOps' models
directly (ADR-0004).**

This is the resolution ADR-0024 asked for explicitly: "Where the game
catalogue itself lives is a separate question and is not settled here... it
has to be revisited when `Discipline` is designed." It is hereby answered:
GameOps keeps the catalogue.

ADR-0024's own field-level evidence, however, is not fully satisfied by a
"name/icon read model" and this needs stating plainly rather than glossed
over. ADR-0024 measured that Competition's `DurationEstimator` reads
`avg_match_minutes` and `avg_stage_minutes` directly off the game catalogue,
that `signup_rules` drives Competition's default eligibility tree, and that
`GameMode.team_size` defaults the competition form — none of that is display
data. D-GOP-04's own chosen wording ("owns games/modes/server requirements...
a name/icon read model") reads narrower than what ADR-0024 found Competition
actually needs. Read together: GameOps remains the source of record for
duration hints, eligibility defaults, and mode metadata exactly as ADR-0024
describes; the *read model* Competition projects from GameOps' events must
carry those fields, not only a name and an icon, or the estimator and
eligibility defaults ADR-0024 documented have nowhere to live. This ADR
records the ownership split; it does not shrink the read model's contents
below what Competition was already shown to need.

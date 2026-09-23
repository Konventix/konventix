# ADR-0062: The Discipline is the source of record for contest defaults

**Status:** accepted (2026-09-18, design at
docs/superpowers/specs/2026-09-18-competition-design.md; GitLab #33, #179).
Amends ADR-0012's D-GOP-04 a second time; ADR-0024 stands.

> **Amended 2026-09-23 by competition design §5.6** (operator decision
> 2026-09-22, event-hub design §13 row B). Point 3 is narrowed: the
> Steam-linked **signup rule** is the discipline's eligibility default
> (`disciplines.requires_linked_provider`, point 1), enforced by Competition
> against its own projection of Identity's link events. What stays with the
> GameOps integration (#115) is `PlayerGameAccount`, the provider account ids
> a match handler needs. Point 2 gains one field in spirit: the catalogue
> event may *suggest* a linked-provider requirement the discipline form
> prefills from, as it suggests `team_size`, and never sets it. Everything
> else in this ADR stands unchanged.

## Context

D-GOP-04 (ADR-0012, Round 6) put the game catalogue in GameOps and told
Competition to keep "a read model from GameOps events — carrying the fields
ADR-0024 documented Competition actually consumes (avg match/stage minutes,
signup rules, team size), not just name/icon". That was the honest reading
of the LanCore evidence at the time: in LanCore those fields sat on `games`
and Competition read them.

Two things have happened since. Slice 10a shipped `Discipline` with
`avg_match_minutes`, `team_size` and `allow_draws` as **local columns**,
because ADR-0024 says a schedule must still compute with GameOps switched
off and because GameOps' producer did not exist — the catalogue event is
still not defined in GameOps' SPEC. And the Eventula dump measured what
SXLAN actually contests: of 27 real tournaments, 6 are not video games at
all (table football, table tennis, chess, Qango), 4 are not head-to-head,
and only 4 could be automated. A discipline with no game is the common
case, not the edge.

So D-GOP-04 as worded asks for the same three fields to live twice — on
`disciplines` for the disciplines that are not games, and in a projection
from GameOps for the ones that are — with a rule for which copy wins.
That is a second source of truth for the one set of numbers the schedule
depends on, and #33's second acceptance criterion ("Competition projiziert
die Felder aus D-GOP-04") would build it.

## Decision

1. **`Discipline` is the source of record for contest defaults**: duration
   hints (`avg_match_minutes`), `team_size`, `allow_draws`, and — when they
   exist — default eligibility and scoring shape. An organizer sets them on
   the discipline, for every kind of discipline the same way. Nothing in
   Competition reads them from anywhere else.

2. **GameOps' catalogue event carries identity and operational fields
   only**: `game_id`, `name`, `slug`, `icon_media_id`, `engine`, and the
   game's modes with their `team_size` as a *suggestion* the discipline form
   may prefill from. Competition projects it into a picker read model
   (`competition_game_catalogue`) that exists so the discipline form can
   offer "this discipline is the video game X" and so `MatchScheduled` can
   carry `game_id` and `game_mode`. Durations, signup rules and eligibility
   are not in the event.

3. **GameOps keeps what only GameOps needs**: server requirements, handler
   selection by `engine`, pools, credentials. `PlayerGameAccount` and the
   Steam-linked eligibility check belong to the GameOps integration (#115),
   not to the discipline.

D-GOP-04's ownership split — GameOps owns games, modes and server
requirements; Competition references a game by ULID and never reads a
GameOps model — is unchanged. What changes is the *content* of the read
model, back to what ADR-0012 originally called it: display data plus the
identifiers needed to hand a match to GameOps.

## Consequences

- #33's second half is answered without a GameOps producer: the discipline
  already carries the fields, and the catalogue event shrinks to a picker
  feed that can arrive with slice 11 whenever it does. #179's "Katalog-Event
  (D-GOP-04) definieren" item takes this payload.
- A video-game discipline can be created **before** its GameOps game exists
  (as every SXLAN discipline is today) and linked to one later. The
  discipline form's game picker is empty for a brand without GameOps, which
  is a correct empty state, not a broken one.
- The `DurationEstimator` (#117) reads the discipline. If GameOps ever
  learns better numbers from real match sessions, it publishes them and an
  organizer accepts them into the discipline; it does not overwrite.
- ADR-0012 has now been amended three times (ADR-0019, D-GOP-01..04,
  this). Its remaining unamended content is the orchestration event flow,
  the provider and handler contracts, and the provisioning modes — #179
  consolidates it and should say so at the top of the document.

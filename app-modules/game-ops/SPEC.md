# GameOps — Specification

> Stable document. Describes what this module *is*. Task tracking lives in
> `TODO.md`.

## Purpose

GameOps runs the machinery behind a match: it provisions game servers through
provider drivers, configures and starts matches through handler drivers, watches
them while they run, and tears them down afterwards. It owns server and handler
**lifecycle** state — `Deployment` and `MatchSession` — while Competition owns
match **result** state (ADR-0012). It is the explicitly LAN-specific plugin:
nothing else in Konventix knows what a Counter-Strike server is, and no other
module needs to. That is the point. GameOps proves the plugin architecture — a
module that is deeply domain-specific, that a majority of brands will never
switch on, and that couples to the rest of the platform through nothing but
events. It is also the one LanCore domain whose event-driven boundaries held in
practice, which is why it is ported as designed and treated as the template for
every other module.

## Owns

- **Game** — the catalogue entry for a title: its name, the modes it supports,
  its default match handler, and the provisioning defaults that hang off it.
  Competition references a game by ULID; the catalogue lives here.
- **GameMode** — a playable variant of a game; static server pools are scoped by
  it (ADR-0012).
- **ServerPool** — a set of servers available for a game, either defined
  statically at Game level and scoped by GameMode, or sized automatically:
  `floor(max_participants / team_size / 2)` concurrent matches.
- **Deployment** — one provisioned game server and its lifecycle state
  (requested, provisioning, ready, in use, destroying, destroyed, failed),
  together with the provider's external identifier and the connection details
  the players need.
- **MatchSession** — the handler-side lifecycle for one match on one deployment:
  configured, started, live, ended, failed. It references
  **`competition_match_id` as a plain ULID value — never an Eloquent relation**
  (ADR-0012, ADR-0014). There is no foreign key constraint on that column, and
  no `use` of any Competition model anywhere in this module.
- **Provisioning mode per competition** — Auto Before Signup, Auto During
  Signup, Auto Before Start, or Static; stored here, keyed by `competition_id`.
- **The contracts and their drivers**:
  - `GameServerProvider` — `provision`, `destroy`, `getStatus`. First driver:
    Pelican Panel.
  - `MatchHandler` — `configure`, `start`, `getState`, `getResult`. First
    driver: TMT2, over its API client.
  - `MatchHandlerResolver` — picks the handler for a given game and match type.
  - `DeploymentStrategyResolver` — decides server-to-match cardinality, 1:1 or
    shared, per game and handler type.
- **Provider and handler credentials** and endpoint configuration, encrypted and
  brand-scoped.
- **PlayerGameAccount** *(proposed)* — a read model of the game identities a
  handler needs to configure a match (for example Steam IDs), populated from
  Identity's social-provider events (ADR-0004).

## Does not own

| Nearby concept | Owned by |
|---|---|
| Match results, scores, winners, standings, forfeits | **Competition** (ADR-0012). GameOps reports what a handler observed; it never records the sporting truth. |
| Competitions, entrants, rosters, the match schedule | **Competition** |
| Bracket structure and pairing logic | **LanBrackets**, an external service integrated over webhooks (ADR-0002). GameOps never talks to it; Competition does. |
| Player accounts, authentication, Steam and other social logins as identity | **Identity** (Steam is one `SocialProvider` driver among many). GameOps keeps `PlayerGameAccount` as a read model of the game handle only. |
| LAN seats, rooms, hardware on the venue floor | **Resources**. A Deployment is ephemeral, provider-managed infrastructure with its own lifecycle — it is deliberately not a bookable Resource. |
| Whether a player may be on site | **Admission**; the ticket that got them there, **Registration** |
| Telling a player their server is ready | **Messaging**. GameOps emits `MatchSessionLive`; delivery and channel are not its business. |
| Which brands may use the module | **Platform** (Pennant, ADR-0003); which roles may retry a deployment, per-brand role data (ADR-0013) |
| Queue worker topology and timeouts | The host application. GameOps names the queue its long-running provisioning jobs run on; the deployment splits workers by queue name. |
| Server hosting costs, provider billing | Outside the platform entirely. No fund flows, ever (ADR-0007). |

## Public contract (events)

Events are the only way other modules may react to this one (ADR-0004).
Payloads carry ULIDs and scalars — never Eloquent models.

`MatchSessionLive` and `DeploymentDestroyed` are named in ADR-0012; the
remaining rows are **proposed**.

| Event | Emitted when | Payload |
|---|---|---|
| `MatchSessionLive` | Provisioning, configuration, and start have all succeeded and the server accepts players | `match_session_id`, `competition_match_id`, `deployment_id`, `game_id`, `connect_host`, `connect_port`, `started_at` |
| `DeploymentDestroyed` | Teardown finished and the server is gone or returned to its pool | `deployment_id`, `competition_match_id` (nullable), `game_id`, `destroyed_at`, `returned_to_pool` |
| `DeploymentProvisioned` *(proposed)* | A server is ready but no match has been configured on it yet | `deployment_id`, `game_id`, `game_mode`, `pool_id`, `provider`, `ready_at` |
| `DeploymentProvisioningFailed` *(proposed)* | The provider could not deliver a server after the configured attempts | `deployment_id`, `competition_match_id` (nullable), `game_id`, `failed_at`, `reason` |
| `MatchSessionFailed` *(proposed)* | A configured or running session broke: the handler errored, or heartbeats stopped | `match_session_id`, `competition_match_id`, `deployment_id`, `failed_at`, `reason` |
| `MatchResultReported` *(proposed)* | The handler's `getResult` produced a final score | `match_session_id`, `competition_match_id`, `scores` (scalars per side), `reported_at` |
| `SessionUnhealthy` *(proposed, amended ADR-0012, D-GOP-03)* | Heartbeat/provider-status loss past the configured threshold | `match_session_id`, `competition_match_id`, `deployment_id`, `last_seen_at`, `detected_at` |

`MatchResultReported` is deliberately named as a *report*, not a result: it
carries what the handler saw, and Competition decides what becomes the recorded
result. The ownership split in ADR-0012 survives only if this event never writes
result state.

## Consumes

Events from other modules that this module listens to, and the read models it
maintains from them.

| Event | Owning module | Why |
|---|---|---|
| `MatchScheduled` | Competition | The trigger of the whole flow: GameOps creates a MatchSession, resolves handler and deployment strategy, provisions or claims a server, configures it, and starts the match — then emits `MatchSessionLive`. |
| `MatchFinished` | Competition | Tears the deployment down or returns it to its pool, then emits `DeploymentDestroyed`. |
| `MatchCancelled` *(proposed)* | Competition | Same teardown path for a match that will never be played. |
| `MatchRescheduled` *(proposed)* | Competition | Adjusts or postpones provisioning under the Auto Before Start mode. |
| `CompetitionPublished` / `SignupOpened` *(proposed)* | Competition | Drives the Auto Before Signup and Auto During Signup provisioning modes and the automatic pool sizing. |
| `SocialIdentityLinked` / `SocialIdentityUnlinked` *(proposed)* | Identity | Maintains `PlayerGameAccount` from `user_id`, `provider`, and `provider_user_id` — the game handles a handler needs when configuring a match. Idempotent and replayable (ADR-0004). |
| `DataExportRequested` / `UserDeletionRequested` *(proposed)* | Compliance | Fans out to `src/Gdpr/`: removes the person's `PlayerGameAccount` rows, detaches them from session logs, and reports completion back to Compliance. |

## Capabilities

Declared in `src/Enums/Permission.php`. Roles that grant them are per-brand
data, not code (ADR-0013).

- Manage the game catalogue and its modes.
- Manage server pools, provisioning modes, and pool sizing.
- Configure provider and handler credentials — the most sensitive capability in
  the module.
- View deployments and match sessions, including connection details.
- Operate deployments manually: provision, retry a failed provisioning, force
  teardown.
- Operate match sessions manually: reconfigure, restart, abandon.

## Activation

Pennant feature name: **`game-ops`** (the module's kebab-case name, matching
`GameOpsServiceProvider::moduleName()`), gated per brand at all four enforcement
points (ADR-0003): the route group carries `ensure-module:game-ops`, Platform's
nav registry filters the module's entries, UI slots check the feature, and every
listener short-circuits when the feature is off for the event's brand context.
The listener gate matters most here: an ungated listener would provision real
servers and spend real money for a brand that never asked for the module.

With the feature off a brand can still run tournaments — Competition schedules
matches, records results, and produces standings exactly as before — but nothing
is provisioned or configured automatically. Servers are operated by hand and
their addresses shared out of band. The reverse dependency also holds: switching
`game-ops` on without `competition` gives a brand nothing, because every trigger
this module reacts to originates in Competition.

## Open questions

Carried over from ADR-0012 — **all three resolved in the decision log Round 6
gate review and written into the amended ADR-0012.** What remains is
implementation against that amendment, not further decision-making:

1. ~~**Pool reuse between rounds.** Does a deployment go back into its pool
   after a match, or is every match a fresh server? Reuse saves provisioning
   time in a tight tournament schedule and risks state bleeding from the
   previous match.~~ **Settled by the amended ADR-0012 (D-GOP-01):**
   configurable per server pool (user decision); fresh-per-match is the
   default mode, reuse mode requires a `MatchHandler` reset hook.
2. ~~**Fallback strategy for failed provisioning.** When the provider cannot
   deliver, what happens: retry with backoff, fall back to a static pool
   server, or hand the match to the tournament desk? Whatever is chosen must
   reach Competition as an event, because a stalled match with no signal is
   the worst outcome.~~ **Settled by the amended ADR-0012 (D-GOP-02):**
   bounded retries with backoff, then hand off to the tournament desk via
   event. No static-pool rescue tier.
3. ~~**Match session failure and heartbeat handling.** How is a session known
   to be alive — handler polling, heartbeats, or provider status — and what
   happens when it stops answering mid-match?~~ **Settled by the amended
   ADR-0012 (D-GOP-03):** provider status plus handler heartbeats; loss past
   threshold emits `SessionUnhealthy` for the desk — never automatic
   mid-match teardown.

Further, beyond ADR-0012:

- Provisioning runs long and calls external systems. Which queue does it use,
  with what timeout, and how are the jobs made idempotent so a retried listener
  never provisions twice for one `MatchScheduled`?
- Where do provider and handler credentials live, given they are per brand and
  must not sit in a free-form settings blob (ADR-0009)?
- ~~Competition needs a game's name for display. Does GameOps expose it
  through an event that Competition projects into a read model, and what
  happens to that display data when the module is off for a brand?~~
  **Settled by the amended ADR-0012 (decision log Round 6, D-GOP-04):**
  catalogue ownership is confirmed here — GameOps owns games, modes, and
  server requirements outright. Competition's `Discipline` references a game
  by ULID and keeps a name/icon read model projected from GameOps' events;
  what that display data does when the module is off for a brand is still an
  implementation detail, not an ownership question.

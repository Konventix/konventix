# Competition / GameOps — LanCore inventory

Input for the deepening of `app-modules/competition/SPEC.md` and
`app-modules/game-ops/SPEC.md`. It records what LanCore actually does — measured,
not remembered — and what that implies for the port.

One finding governs the rest and should be read first: **ADR-0012 is not a port
description.** Nothing it names beyond "pick an existing server row and deploy to
it" exists in LanCore. `Deployment`, `MatchSession`, `GameServerProvider`,
`MatchHandlerResolver`, `DeploymentStrategyResolver`, provisioning modes, Pelican,
and the pool-sizing formula return **zero grep hits** across LanCore's `app/`,
`config/` and `docs/`. LanCore has a hand-maintained `GameServer` inventory table
and an `OrchestrationJob` state machine. ADR-0012 is a green-field design that
happens to sit in the decisions folder; the module's TODO is right to put its three
open questions before the migrations, because there is no working code to fall back
on when they are answered wrongly.

## Sources

| Source | What was read |
|---|---|
| `/home/mkohn/git/lan-software/LanCore/docs/mil-std-498/SRS.md` | §3.2.13 Games (GAM-F-001..003), §3.2.14 Competition (COMP-F-001..021, COM-F-MATCH-FINAL-001..003, COMP-SCH-001..010 + Deferred items), §3.2.16 Orchestration (ORC-F-001..015), §3.2.17 Api (API-F-001..002) |
| `LanCore/docs/mil-std-498/SSS.md` | CAP-GAM-001..002, CAP-COMP-001..005, CAP-ORC-001..011 |
| `LanCore/app/Domain/Competition/` | 73 files, 4,875 LOC — all models, actions, enums, signup rules, chat integration, webhook handler |
| `LanCore/app/Domain/CompetitionSchedule/` | 21 files, 1,956 LOC — schedules, estimator, conflict detector, next-match proposer, board controller |
| `LanCore/app/Domain/Orchestration/` | 34 files, 1,768 LOC |
| `LanCore/app/Domain/Games/` | 18 files, 662 LOC |
| `LanCore/database/migrations/` | 16 competition/game/orchestration tables |
| `LanCore/routes/{competitions,competition-board,games,orchestration}.php`, `config/{integrations,lanbrackets,tmt2}.php` | route and integration surface |
| `LanCore/resources/js/pages/{competitions,games,orchestration,events/CompetitionBoard.vue}` | 4,252 LOC of competition Vue, plus games/orchestration admin pages |
| `/home/mkohn/git/lan-software/LanBrackets` | 206 PHP files (110 in `app/`, 8,394 LOC), 149 `.vue` (8 real pages), 19 migrations, 34 test files, `docs/{architecture,api,formats}.md` |
| Konventix | `CLAUDE_extra.md`, `docs/architecture/{overview,module-map}.md`, `docs/lessons-from-lancore.md`, ADR-0002/0004/0009/0012/0013/0014, `app-modules/{competition,game-ops}/{SPEC,TODO}.md` |

Counts referenced below were measured with `find`/`wc`/`grep` against the two
read-only repos on 2026-08-05.

## Feature inventory

Verdicts: **Port as-is** / **Port renamed** / **Generalize** / **Split across
modules** / **Drop** / **Defer post-release**. The LanBrackets block adds one more,
**Absorb** — move the capability out of the external service and into Competition; it
only applies there, and only if D-CMP-01 resolves that way.

### Competition and CompetitionSchedule

| LanCore feature | SRS ID | What it does today | Konventix module | Proposed name | Verdict |
|---|---|---|---|---|---|
| Competition CRUD and lifecycle | COMP-F-001, -003, -004 | 7-state machine Draft ↔ Published → RegistrationOpen ↔ RegistrationClosed → Running → Finished → Archived, with reversible edges and `allowedTransitions()` on the enum; delete only from draft/archived. Admin edit page is a **1,024-LOC** single Vue file | Competition | `Competition`, `CompetitionStatus` | Port as-is (decompose the page) |
| `CompetitionType` and `StageType` enums | COMP-F-001 | Type: `tournament`, `league`, `race` — `race` is declared but drives no behaviour anywhere. Stage: 7 cases, of which `race_heat` and `final_stage` are unimplemented stubs on both sides (LanCore *and* LanBrackets) | Competition | `ContestFormat`, `StageType` | Generalize (type) / Port as-is (stage) |
| Ruleset text, logo, banner | — (undocumented) | `rules_markdown`, `logo_path`, `banner_path` on `competitions`, falling back to the game's logo/banner | Competition | same | Port as-is |
| `settings` + `metadata` JSON blobs | — | Two schemaless `json` columns on `competitions`, magic-string access | — | — | Drop (lesson 11, ADR-0009) |
| `CompetitionPolicy` / `ManageCompetitions` | COMP-F-002 | A single permission gates all admin CRUD | Competition | `Permission` enum, 6 capabilities | Generalize (SPEC already splits it six ways) |
| Team creation with captain | COMP-F-005 | `CreateTeam` assigns `captain_user_id`, unique `(competition_id, name)` | Competition | `Entrant` + `EntrantMember` | Port renamed |
| Team joining with capacity check | COMP-F-006 | `JoinTeam` validates against `competition.team_size` | Competition | `JoinEntrant` | Port renamed |
| Team leaving with captaincy rotation | COMP-F-007 | Non-last member leaves → captaincy rotates to oldest-joined active member; last member leaves → team deleted; returns `bool` | Competition | `LeaveEntrant` | Port as-is |
| Team join requests | COMP-F-013, -014, -015 | `team_join_requests` with pending/approved/rejected, duplicate and capacity guards, notifications to captain and requester | Competition | `EntrantJoinRequest` | Port renamed |
| Team invites by e-mail token | — (undocumented) | `team_invites`: 64-char token, `expires_at`, accept/decline, public `/team-invites/{token}` page; invitee may not yet have an account | Competition | `EntrantInvite` | Port as-is |
| Admin team management | — (undocumented) | `AdminTeamController` — rename team, remove members; `TeamEdit.vue` is 448 LOC | Competition | `EntrantAdminController` | Port renamed |
| Referees per competition | — (undocumented) | `competition_referees` pivot (`competition_id`, `user_id`); consumed only by chat member annotation and a `RefereePicker.vue` | Competition + Staffing | `Official` | Split across modules — the role is Competition's, the person and their shift are Staffing's |
| Signup rule engine | — (undocumented) | `SignupRuleRegistry` + 4 rules (`has_steam_account_linked`, `has_ticket_of_type`, `has_seat`, `has_addon`), stored as a JSON tree on `competitions.signup_rules` with fallback to `games.signup_rules`; `SignupRuleEditor.vue` builds the tree | Competition | `EligibilityRule` | Generalize — see D-CMP-05 |
| Match result submission with screenshot proof | COMP-F-008 | `match_result_proofs`: screenshot upload, `scores` JSON, `is_disputed` (never read by anything), `resolved_at`; proxied straight to LanBrackets. Mode is per competition: `ResultSubmissionMode` = `admin_only` or `participants_with_proof` | Competition | `ResultSubmission`, `ResultSubmissionMode` | Port as-is |
| `MatchFinalized` + once-only marker | COM-F-MATCH-FINAL-001..003 | Atomic `Cache::add` marker, 30-day TTL, keyed `(competition_id, match_id)`; `MatchFinalizationSource` = participant-confirmed or admin-forced | Competition | same | Port as-is — this is the idempotency pattern every webhook consumer needs |
| User-facing competition views | COMP-F-012 | `/portal/competitions*`, scoped to team membership; `user/Show.vue` 482 LOC, `user/TeamShow.vue` 496 LOC | Competition | same | Port as-is (split the pages, ~300 LOC rule) |
| LanBrackets webhook ingest | COMP-F-009, -019, -021 | HMAC-verified endpoint handling `competition.completed`, `match.result_reported`, `bracket.generated`, `stage.completed`; the `stage.completed` branch locates the next pending stage over HTTP and chains generation; subscribed set declared in `config/integrations.php` | Competition | `BracketWebhookController` | Port as-is *if* LanBrackets stays external (D-CMP-01) |
| Outbound LanBrackets sync | COMP-F-010, -011, -018, -020 | `SyncCompetitionToLanBrackets`; team upsert via `POST /api/v1/teams` carrying `external_reference_id` + `source_system=lancore`, idempotent on the tuple, persisting the returned id into `competition_teams.lanbrackets_id` before bulk-adding participants; and the reverse translation of `external_reference_id` back to a local team, with a null-fallback for legacy payloads | Competition | — | Drop if absorbed — four requirements and an entire bug class disappear |
| Stage generation on registration close | COMP-F-016 | `Bus::chain([SyncTeamsToLanBrackets, GenerateLanBracketsStages])` | Competition | `GenerateBracket` | Port renamed / becomes in-process if absorbed |
| Recovery artisan commands | COMP-F-017 | `competitions:generate-matches`, `competitions:resync`, `competitions:sync-stages`, plus `competitions:create-team` / `add-team-member`. A hand-run replay for lost webhooks is a *specified requirement* — a fair summary of the integration's reliability | Competition | same | Port as-is |
| `MatchReadyForOrchestration` / `MatchCompleted` events | ORC-F-006 (consumer side) | Emitted from the LanBrackets webhook handler, **carrying an Eloquent `Competition` and the raw LanBrackets match array** | Competition | `MatchScheduled` / `MatchFinished` | Port renamed — payload must become ULIDs + scalars (ADR-0004) |
| Stage and round schedules | COMP-SCH-001, -002; COMP-RND-003 (**cited in code, absent from SRS**) | `competition_stage_schedules` keyed `(competition_id, lanbrackets_stage_id)`, with `competition_round_schedules` beneath them carrying per-round duration and reserve; `StageScheduleSynchronizer` upserts from LanBrackets, deletes orphans, preserves organizer overrides, refreshes `computed_inputs_hash`; `RoundDurationDefaults` supplies elimination/Swiss/RR/heat defaults. The SRS "Deferred items" section still calls rounds unbuilt — the code disagrees | Competition | `StageSchedule`, `RoundSchedule` | Port as-is (key becomes a local stage ULID if absorbed) |
| `DurationEstimator` and match-length inheritance | COMP-SCH-003 | `match_count × game.avg_match_minutes`, with per-stage-type fallbacks; emits a `DurationEstimate` carrying a SHA-256 hash of its inputs so drift is detectable. A separate, harder ceiling resolves `Competition.match_length_minutes > GameMode > Game > null` | Competition (+ GameOps as the source of the game-level values) | same | Port as-is; the game-level fields are a split — see D-CMP-04 |
| `ConflictDetector` | COMP-SCH-004 | Player double-booking across overlapping stages, runs outside `Event.start_date..end_date`, intra-competition sequence overlap | Competition | same | Port as-is |
| `NextMatchProposer` / "Play Next" | COMP-SCH-005, -010 | Pulls pending matches from every Running competition via LanBrackets, filters unresolved dependencies, ranks by window proximity and deadline urgency, marks blocked participants; 30 s cache; player surface `/portal/play-next` | Competition | same | Port as-is — but it makes N live HTTP calls per page load today (D-CMP-01) |
| Competition Board (Gantt) | COMP-SCH-006, -007, -008, -009 | `/backstage/competition-board`, event chosen by session key not URL; three columns (competition, slack, adaptive-zoom timeline); `StageScheduleUpdated` broadcast over Reverb on every edit; an undocumented auto-fit lays unscheduled stages end-to-end from `competition.starts_at` respecting reserve buffers; `ManageCompetitions` gates the view, every edit, and the private channel | Competition | same | Port as-is |
| Backwards mapping, slack triple, breaks, BoN | SRS "Deferred items" | Specified in `competition-board.md`, not built. Current forward-mapped slack is explicitly an approximation | Competition | — | Defer post-release |
| Competition + match chat rooms | CHT-F-027..030 | Room lifecycle driven off `competition.status`; auto-join on team membership; per-match rooms; member annotation with `is_admin` / `is_referee` / `team_name` | Community (post-release) | — | Defer post-release — Community is Phase 3; keep the *hooks* (events) so it can be added without touching Competition |
| GDPR data source | — | `CompetitionDataSource` exports team memberships, invites, join requests, result submissions | Competition | `src/Gdpr/` | Port as-is |

### Games and Orchestration

| LanCore feature | SRS ID | What it does today | Konventix module | Proposed name | Verdict |
|---|---|---|---|---|---|
| Game catalogue and modes | GAM-F-001, -002 | `games`: name, slug, publisher, description, `is_active`, logo/banner. `game_modes`: `team_size` (default 1), `parameters` JSON, `is_active`. Games is the one LanCore domain that imports nothing from any other domain | GameOps | `Game`, `GameMode` | Port as-is; `parameters` must become a typed cast (ADR-0009) |
| Game/GameMode policies | GAM-F-003 | Single `ManageGames` permission for both | GameOps | `Permission::ManageGameCatalogue` | Port renamed |
| Contest defaults on Game/GameMode | — (undocumented) | `avg_match_minutes`, `avg_stage_minutes`, `match_length_minutes`, `signup_rules` (default eligibility tree, overridden per competition), `logo_path`/`banner_path` (Competition falls back to them). Every one of these is read by Competition, none by Orchestration | GameOps → Competition, or Competition outright | `GameProfile` | Split across modules — see D-CMP-04 |
| `GameServer` inventory and allocation | ORC-F-001, -002, -004 | Hand-maintained rows: host, port, `game_id`, `game_mode_id`, status, `encrypted:array` credentials, `metadata` JSON, plus allocation type `competition` / `casual` / `flexible` | GameOps | `GameServer` (static pool) / `Deployment` (provisioned) | Port renamed, split in two — see D-CMP-10 |
| Server selection | ORC-F-005 | `orderByRaw` CASE competition > flexible > casual, secondary ordering on exact game-mode match, `lockForUpdate()` inside a transaction. No `SKIP LOCKED`, so concurrent selections serialize | GameOps | `ClaimServerForMatch` | Port as-is, with `SKIP LOCKED` |
| `OrchestrationJob` | ORC-F-006, -009, -010, -011 | One row per `(competition_id, lanbrackets_match_id)` — the unique constraint is the dedup mechanism; 7-state enum whose `allowedTransitions()` is **defined but never enforced** by any action; server status returns to available on completion or failure | GameOps | `MatchSession` | Port renamed — enforce the transitions this time |
| Queue and retry | ORC-F-007 | `ProcessMatchOrchestration`, `tries=3`, `backoff=10`, dedicated `orchestration` queue | GameOps | same queue split | Port as-is |
| `MatchHandlerContract` | ORC-F-008 | `supports(Game): bool`, `deploy(GameServer, array): void`, `teardown(GameServer, array): void`, `healthCheck(GameServer): bool` | GameOps | `MatchHandler` | Port renamed — ADR-0012's `configure/start/getState/getResult` is a **different, larger** interface, not a rename |
| `Tmt2MatchHandler` and `ResolveMatchHandler` | ORC-F-008, API-F-001..002 | The handler builds a TMT2 match payload (map pool, teams by Steam ID 64, election steps, RCON commands, webhook URL) and is selected by `$game->metadata['engine'] === 'source2'` — but `metadata` **is not a column on `games`**, so the only handler in the system can never match today. `ResolveMatchHandler` is an Action iterating container-tagged handlers, first `supports()` wins; exactly one is tagged | GameOps | `Tmt2MatchHandler`, `MatchHandlerResolver` | Port as-is, bug fixed — replace the blob probe with a typed `engine` field; ADR-0012's resolver also keys on match *type*, which does not exist here |
| `SupportsChatFeature` + `MatchChatMessage` | ORC-F-012 | Optional handler capability; stores in-game chat (`steam_id`, `player_name`, `message`, `is_team_chat`) | GameOps | same | Port as-is — but the chat *display* lands in Community, post-release |
| TMT2 webhook | ORC-F-013 | `POST /webhooks/tmt2/{orchestrationJob}` with **no authentication** — the route comment claims "secured by URL secret" but the secret is the job's ULID; no HMAC, no token. `MATCH_END` maps `wonMapsTeamA/B` to slots 1/2 and reports to LanBrackets, swallowing errors into a log line | GameOps | `MatchHandlerWebhookController` | Port as-is, **signed** — this is a security defect, not a design |
| Admin manual controls | ORC-F-014 | Retry (failed only), cancel (pending/failed only), force-release (cancels the active job and frees the server). The existence of force-release is the tell that nothing detects a stuck session — see D-CMP-08 | GameOps | same | Port as-is |
| Participant connection info | ORC-F-015 | **Not implemented.** Host/port appear on the admin job page only; no participant-facing surface exists, and no password is ever shown | Competition (display) + GameOps (source) | — | Split across modules — GameOps emits `MatchSessionLive` with connect details, Competition renders them |
| External API test dashboard | CAP-ORC-011 / EXT-F-001..005 | 955-LOC Vue page testing TMT2, Stripe, PayPal, Steam and Listmonk from inside the Orchestration domain — the reason Orchestration imports `Shop` and `Newsletter` | Platform | `IntegrationHealth` | Split across modules — GameOps keeps only the TMT2 card |
| Orchestration policies | ORC-F-003 | `ManageGameServers`, `ViewOrchestration` | GameOps | `Permission` enum, 6 capabilities | Generalize |
| `Deployment`, provisioning modes, provider drivers, pool sizing | ADR-0012 | **Does not exist.** Zero hits for Pelican, `Deployment`, `MatchSession`, `GameServerProvider`, `DeploymentStrategyResolver`, "provisioning", or the pool formula | GameOps | as in ADR-0012 | New build, not a port — see D-CMP-10 |

### LanBrackets (external service — its own block)

| LanBrackets capability | SRS ID | What it does today | Konventix module | Proposed name | Verdict |
|---|---|---|---|---|---|
| Bracket domain model and progression graph | — (LanBrackets has no SRS) | `Competition`, `CompetitionStage`, `CompetitionMatch`, `MatchParticipant` (slot 1/2, score, result), `CompetitionParticipant` (morph to Team\|User, seed, status, `checked_in_at`), `CompetitionStageGroup(+Member)`, and `MatchConnection(source_match, source_outcome, target_match, target_slot)` carrying winner/loser/placement edges | Competition | `Stage`, `Match`, `MatchSlot`, `Entrant` | Absorb — see below |
| Format engines and rulesets | — | 5 implemented (single elim 521 LOC, double elim 982, round robin 289, Swiss 571, group stage 449) behind 4 contracts (`FormatGenerator`, `FormatResolver`, `FormatRuleset`, `StandingsCalculator`); `race_heat` and `final_stage` registered as `null` stubs. Rulesets are abstract — `best_of`, `points_win/draw/loss`, `allow_draws`, `group_size`, `grand_final_reset`, `tiebreaker` — with no game, map-pool or title-specific field anywhere in the codebase | Competition | `Formats/`, `Ruleset` | Absorb — participant-agnostic, well-tested, and the evidence the engine already generalizes past esports |
| Standings | — | Computed at query time per format; **no persisted standings model** | Competition | `Standings` (projection) | Absorb, then persist — the SPEC lists Standings as owned |
| REST API v1 and `ApiToken` auth | — | 21 endpoints under `/api/v1/{competitions,teams,webhook}`, behind a custom bearer scheme (`lbt_…`, SHA-256 at rest, no Sanctum/OAuth); the token row doubles as the webhook config | — | — | Drop — becomes in-process Actions |
| Outbound webhooks | — | 4 events fanned out to every `ApiToken` with a `webhook_url`, signed `X-LanBrackets-Signature` HMAC-SHA256, delivered **synchronously via `Http::timeout(5)`, no queue, no retry, failures only logged** | — | — | Drop — becomes Laravel events |
| Own user accounts + LanCore SSO | — | Local `User` with `lancore_user_id`, OAuth code exchange via `lan-software/lancore-client`, a legacy HMAC-signed-redirect path, a `user.roles_updated` webhook receiver, and a dead `AuthenticateLanCoreUser` middleware | — | — | Drop — pure integration overhead |
| `(source_system, external_reference_id)` | — | Partial unique indexes on `competitions` and `teams`; genuinely multi-consumer by design, with exactly one consumer in practice | — | — | Drop |
| Canvas bracket renderer | — | `useBracketCanvas.ts`, 758 LOC — pan/zoom/drag bracket tree, shared by the admin viewer and the overlay | Competition | `BracketCanvas` | Absorb — the single most valuable piece of frontend in either repo |
| OBS overlay | — | Token-gated public `/overlay/competitions/{id}` page + JSON endpoint, keyed on `competition.share_token` | Competition | `OverlayController` | Absorb |
| Artisan tooling | — | 13 commands: create/list competitions, add participant, generate bracket, report result, ASCII bracket tree, format-flow test | Competition | same | Absorb selectively |
| Read-only web admin | — | Only `index` and `show` are routed; there is **no** create form, no result-reporting form, no participant management in the UI. Every mutation is API- or CLI-only | — | — | Drop — Konventix already has the organizer UI |

**Row count: 56** — 29 Competition/CompetitionSchedule, 16 Games/Orchestration,
11 LanBrackets. Rows are consolidated where LanCore split one capability across several
requirement IDs; the SRS ID column names every requirement each row covers.

## LanBrackets: stay external or absorb?

### Size

| | LanBrackets | For comparison |
|---|---|---|
| PHP files total | 206 | LanCore Competition + Schedule: 94 |
| PHP files in `app/` | 110 | — |
| `app/` LOC | 8,394 | LanCore Competition + Schedule: 6,831 |
| Domain logic worth keeping (`app/Domain/Competition/`) | ~3,185 LOC | — |
| Models | 12 (3 of which — `User`, `ApiToken`, `TeamMember` — exist only to serve the separation) | — |
| Migrations | 19 | — |
| Vue files | 149 — of which **125 are shadcn-vue UI primitives**, 12 app-shell, 2 demo, and **8 are real pages** | — |
| Genuinely unique frontend | `useBracketCanvas.ts`, 758 LOC | — |
| Test files | 34 Pest files, format-coverage-heavy | — |
| Queued jobs | **0** | — |

The "206 PHP / 149 Vue" headline overstates it by roughly 3×. The thing that would
actually move is about **3,200 lines of format engines plus a 758-line canvas
renderer**, both already isolated behind contracts, both already participant-agnostic.

### Coupling

LanBrackets is not a neutral third-party service that happens to speak HTTP. It is
LanCore-shaped in ways that only make sense inside the same product:

- It depends on the first-party composer package `lan-software/lancore-client`.
- It has its **own user table** (`users.lancore_user_id`), provisioned from LanCore
  over SSO, with a synthetic `lancore-user-{id}@users.lancore.local` e-mail fallback.
- It **receives** webhooks from LanCore (`POST /api/webhooks/roles`,
  `user.roles_updated`) to keep its local roles in sync.
- LanCore's `config/integrations.php` registers it as an OAuth client with scopes
  `user:read`, `user:email`, `user:roles`, plus announcement and role-update push paths.
- Its `config/lancore.php` defaults `app_slug` to `lanbrackets` and carries a
  `legacy_auth_secret` for LanCore-signed redirect URLs.

So the integration surface is not one REST client and one webhook endpoint. It is a
REST client, an inbound webhook endpoint, an outbound webhook endpoint, an OAuth
client registration, an SSO user-provisioning path, a role-sync path, and a duplicate
user table. All of that is infrastructure for the separation itself.

### What the webhook contract carries today

Body: `{"event": …, "timestamp": …, "data": {…}}`, header
`X-LanBrackets-Signature: hash_hmac('sha256', $rawBody, $token->webhook_secret)`.

| Event | Payload |
|---|---|
| `bracket.generated` | `competition_id, external_reference_id, stage_id, stage_name, match_count` |
| `stage.completed` | `competition_id, external_reference_id, stage_id, stage_name` |
| `competition.completed` | `competition_id, external_reference_id` |
| `match.result_reported` | `competition_id, external_reference_id, stage_id, match_id, round_number, sequence, winner_participant_id, loser_participant_id, scores{slot:score}, participants[{competition_participant_id, slot, score, result, participant_type, participant_id, participant_name, external_reference_id, source_system}]` |

Three of the four carry **no match data at all** — they are "something changed, go
look" pings. LanCore duly goes and looks: `HandleLanBracketsWebhook` responds to
`bracket.generated` and `stage.completed` by calling `getMatches()` and
`getStages()` over HTTP; `NextMatchProposer` calls `getMatches()` for every running
competition on every page load (mitigated only by a 30-second cache);
`StageScheduleSynchronizer` re-reads stages on every sync. **LanCore does not own a
`matches` table.** There is no local match row anywhere in the domain — only
`match_result_proofs.lanbrackets_match_id` and
`orchestration_jobs.lanbrackets_match_id`, both bare external ID strings.

That is the real cost of the boundary: it is drawn straight through the middle of the
aggregate. Rendering a player's match list is a remote call. Detecting scheduling
conflicts is a remote call. Deciding whether a match is ready to orchestrate is a
remote call whose failure mode is `catch (Throwable) { return; }` — matches silently
never get orchestrated, and nothing reports it.

### Delivery reliability

`WebhookDispatcher` uses a synchronous `Http::timeout(5)->post()` with no queue, no
retry, and no dead-letter. A failed delivery is a `Log::warning`. The recovery
mechanism is a human running `competitions:generate-matches`. LanCore's SRS
acknowledges this by specifying that command (COMP-F-017) as a first-class
requirement — a recovery command for a lost webhook is a documented feature, which is
a reasonable summary of the arrangement.

### What breaks either way

**If it is absorbed**, these die and are not missed: the REST API (21 endpoints),
`ApiToken` auth, the webhook dispatcher and its HMAC signing, the duplicate `User`
table plus SSO client plus role-sync webhook, the `(source_system,
external_reference_id)` mapping and every `external_reference_id`-to-local-ULID
translation on the LanCore side (COMP-F-018 and COMP-F-020 cease to be requirements),
and a second deploy target with its own image, database, and TLS.

These must be rebuilt or reworked: `CompetitionMatch`/`MatchParticipant`/
`MatchConnection` become brand-scoped ULID models under `BelongsToBrand`;
`competition_participants`' `participant_type`/`participant_id` morph must be
reconciled with Konventix's `Entrant`; the canvas renderer must be re-themed onto
semantic tokens (ADR-0008); the read-only Vue admin is discarded in favour of
Konventix's own organizer surfaces; and the OBS overlay's `share_token` route has to
survive the brand-resolution middleware, since it is public and unauthenticated.

These are genuinely lost: the ability for a **third organizer** to point their own
system at the bracket service. Nothing in the code prevents it today — the
multi-source design is real — but no second consumer exists, and Konventix's
commercial model (ADR-0011: AGPL open core plus hosted SaaS) does not depend on one.

**If it stays external**, Konventix inherits: two deployments and two databases for a
1–3 person team, in direct tension with ADR-0002's stated reason for existing; a
duplicate identity layer that must now be rebuilt a third time against Konventix's
Identity module rather than LanCore's; a match aggregate split across a network
boundary, which forces Competition either to keep making synchronous calls to render
a page (LanCore's approach, with its `catch (Throwable) { return; }` failure mode) or
to mirror match state locally and reconcile it (D-CMP-02) — and mirroring is most of
the absorption cost with none of the benefit; and an event contract that cannot meet
ADR-0004's payload rules without change, since three of four events carry no data.

### Verdict

**Absorb into Competition.** Supersede ADR-0002's "remains an external service for
now" with a new ADR, and bring the format engines, the progression graph, the canvas
renderer, and the overlay across as `app-modules/competition/src/Formats/` and
`resources/js/`. Retire the REST API, the webhook dispatcher, the `ApiToken` auth,
and LanBrackets' user table.

The single strongest reason: **the boundary runs through the middle of one
aggregate.** A competition's matches are not a separable concern from its
competition — they are the competition — and LanCore proves it by having no local
`matches` table, no local match state, and three of four webhooks that carry nothing
but "go look again". Every feature built on top has to pay for that: the next-match
proposer polls, the conflict detector polls, orchestration triggers depend on a
polled read whose failure is silent, and a documented artisan command exists purely to
replay lost deliveries. Absorbing removes an entire class of failure and a duplicate
identity stack, and costs about 4,000 lines of already-isolated, already-tested,
already-participant-agnostic code moving repositories.

Two honest caveats. First, this is the largest single scope addition to Phase 2 in
this inventory; if it endangers the release, the fallback is to absorb the *data
model* (D-CMP-02) now and the *format engines* later, keeping LanBrackets alive only
as a bracket-generation calculator behind one call. Second, absorbing means Konventix
owns double-elimination and Swiss pairing correctness forever; the 34 Pest files must
come across with the code, not after it.

## Renames

| LanCore term | Konventix term | Why the rename forces a better model |
|---|---|---|
| `Competition` (esports contest) | `Competition` (kept) | Kept deliberately. The word already covers a quiz and a pinball ladder; what has to go is not the noun but its mandatory `game_id`. See D-CMP-03. |
| `CompetitionTeam` | `Entrant` | A team is one *shape* of entrant. Naming the concept for its role rather than its cardinality is what makes a solo quiz player, a two-person hackathon pair, and a five-person CS roster the same row — and it removes the "team of one" awkwardness the LanCore chat annotator already special-cases (`team_size = 1` → hide the team badge). |
| `CompetitionTeamMember` | `EntrantMember` | Follows `Entrant`; also makes clear the member is a competitor, not a staffer. |
| `captain_user_id` | `EntrantMember.role` | LanCore stores captaincy on the team and rotates it in `LeaveTeam`. A role on the membership row supports referee-nominated captains, co-captains, and substitutes without a second column each. |
| `CompetitionType` (`tournament`/`league`/`race`) | `ContestFormat` | `race` is declared and drives nothing. Naming it *format* invites the question "what does this format change?", which is the question that must be answered before a hackathon (judged, not paired) can fit. |
| `stage_type` on the competition row | `Stage` rows | LanCore stores one `stage_type` on the competition *and* has a `competition_stage_schedules` table keyed on external stage ids. One competition, many stages, each with its own type — the schedule table already assumes this; the competition row has not caught up. |
| `lanbrackets_id`, `lanbrackets_match_id`, `lanbrackets_stage_id`, `lanbrackets_share_token`, `external_reference_id`, `source_system` | *(gone)* | Six columns and two ID-translation requirements (COMP-F-018, COMP-F-020) exist only to name a foreign system. Absorbing deletes the vocabulary along with the bug class. |
| `GameServer` (a row an admin typed in) | `GameServer` (static pool) **and** `Deployment` (provisioned) | LanCore conflates "a machine we own" with "a machine allocated to this match" in one `status` column, which is why `ForceReleaseGameServer` exists as an escape hatch. Splitting inventory from allocation makes the escape hatch unnecessary. |
| `OrchestrationJob` | `MatchSession` | "Job" invites the reading that the row *is* the queued job. It is not — `ProcessMatchOrchestration` is. The row is the session's lifecycle state, and the current name is why its `allowedTransitions()` is defined but never enforced. |
| `MatchHandlerContract::deploy/teardown` | `MatchHandler::configure/start/getState/getResult` | Not a rename — a wider interface. `deploy()` currently means configure-and-start in one call, which is exactly why there is no way to observe a running match (ADR-0012 Q3) and no way to distinguish "configure failed" from "start failed". |
| `ResolveMatchHandler` (an Action) | `MatchHandlerResolver` (a service) | An Action implies a business operation with a transaction. This is a lookup. Mislabelling it is why the resolution key is `Game` alone and not `(Game, matchType)`. |
| `ManageCompetitions` (one permission) | six capabilities | A single permission means the volunteer who reschedules a match can also correct a result — the operation with the largest blast radius in the module, since standings derive from it. |
| `settings` / `metadata` / `parameters` JSON blobs | typed casts / value objects | Three schemaless columns across `competitions`, `games`, `game_modes` and `game_servers`. `Tmt2MatchHandler` already reads `$game->metadata['engine']` — a column that does not exist on `games`, so the only handler in the system can never match. The blob hid the bug. |
| `orga-teams` (staff) vs `competition_teams` (players) | Staffing vs Competition | LanCore has two unrelated "team" concepts in one namespace. Splitting the word across modules is what keeps the referee question (D-CMP-03 adjacent) answerable. |

## Dropped / deferred, and why

**Dropped outright**

- **`settings` and `metadata` JSON on `competitions`**, `parameters` on `game_modes`,
  `metadata` on `games` and `game_servers`. Lesson 11 and ADR-0009. The concrete cost
  is already visible: `Tmt2MatchHandler::supports()` probes
  `$game->metadata['engine'] === 'source2'` against a column that does not exist, so
  the only match handler in LanCore never matches anything. A typed field would have
  been a compile-time error.
- **The External API test dashboard** (`orchestration/apis/Index.vue`, 955 LOC, plus
  `ExternalApiController`/`ExternalApiTester`). It is the sole reason the Orchestration
  domain imports `Shop` and `Newsletter`. Its TMT2 card belongs in GameOps; the rest
  belongs in Platform. CAP-ORC-011's requirement survives, its location does not.
- **LanBrackets' REST API, `ApiToken` auth, webhook dispatcher, local `User` table,
  SSO client and role-sync receiver** — all conditional on D-CMP-01 resolving to
  absorb. Every one of them is infrastructure for a separation, not a feature.
- **LanBrackets' read-only Vue admin.** Only `index` and `show` are routed; there is
  no create form, no result-reporting form, no participant management. Konventix
  already has organizer surfaces. The canvas renderer and the overlay are kept; the
  admin shell is not.
- **`AuthenticateLanCoreUser` middleware** in LanBrackets — dead code, checks a session
  key nothing sets.

**Deferred post-release**

- **Competition and per-match chat rooms** (CHT-F-027..030) with auto-join, room
  lifecycle driven off competition status, and member annotation
  (`is_admin`/`is_referee`/`team_name`). Community is Phase 3. What must survive into
  Phase 2 is the *events* Community will need — `CompetitionPublished`,
  `EntrantRegistered`, `MatchScheduled` already do the job — so Community can be added
  without reopening Competition. Note that today's `MatchRoomPolicy` derives
  participation from a membership table precisely because LanBrackets is remote; once
  absorbed, that policy gets simpler.
- **Backwards-mapping scheduler, the `{target, min, max}` slack triple with
  Stage → Competition → Format → Global inheritance, `Break` as a first-class entity,
  and BoN configuration with inheritance resolution.** All specified in
  `competition-board.md`, none built. The current forward-mapped slack column is
  documented in COMP-SCH-009 as an interim approximation. The Gantt board ships without
  them.
- **`race_heat` and `final_stage` stage types.** Registered as `null` stubs on both
  sides. They are the natural first test of D-CMP-03 (does the format abstraction hold
  for a non-paired contest?) but nothing depends on them for release.
- **A protest/dispute workflow.** `match_result_proofs.is_disputed` exists as a boolean
  and nothing reads it. Building the workflow requires answering D-CMP-09 first.
- **Multi-consumer bracket service.** Absorbing forfeits the latent ability for a third
  organizer to drive brackets over the API. If that is ever wanted, it returns through
  Extensibility (Phase 3, ADR: no public API before internal contracts are stable) —
  which is the right tier for it anyway.

**Not deferred, though it looks optional**

- **Signed handler webhooks.** The TMT2 endpoint is unauthenticated today. This is a
  defect to fix in the port, not a feature to schedule.
- **Enforced state transitions.** `OrchestrationJobStatus::allowedTransitions()` and
  `CompetitionStatus::allowedTransitions()` both exist; only the latter is consulted.

## Open decisions

### D-CMP-01: Does LanBrackets stay external, or is it absorbed into Competition?

**Context:** ADR-0012 states tournament trees remain in LanBrackets, integrated via
webhooks. ADR-0002 states the same "for now" and explicitly leaves absorption open, so
this is a door the ADRs left ajar rather than a settled question. Measured: 8,394 LOC
in `app/`, of which ~3,200 is format engines and ~750 is the canvas renderer; the rest
is REST API, token auth, webhook dispatch, and a duplicate user table. LanCore has no
local `matches` table — every match read is an HTTP call. Webhook delivery is
synchronous, unqueued, unretried; COMP-F-017 exists to replay lost ones by hand.

**Option A — stay external, integrate as today.** *Pros:* zero migration work in
Phase 2; the service is running and tested; preserves the latent multi-consumer
capability; ADR-0012 stands unamended. *Cons:* two deployments and two databases for a
1–3 person team, against ADR-0002's stated reason for existing; the identity bridge
(SSO client, role-sync webhook, local `User` table) must be rebuilt against Konventix
Identity, so "no work" is false; Competition cannot own `Match` as its SPEC says
without mirroring (D-CMP-02); the webhook payloads violate ADR-0004's contract rules
and would need reshaping anyway; every match-derived feature stays a network call away
from failing silently.

**Option B — absorb into Competition.** *Pros:* deletes the REST client, both webhook
directions, the token auth, the duplicate user table, the `(source_system,
external_reference_id)` mapping, and requirements COMP-F-018 and COMP-F-020 entirely;
match reads become queries; the format engines are already participant-agnostic
(verified: no game, map, or title-specific field anywhere in LanBrackets), so they
survive D-CMP-03 unchanged; one deploy target. *Cons:* the largest single scope
addition in this inventory, landing in Phase 2; Konventix permanently owns
double-elimination and Swiss pairing correctness; the canvas renderer needs re-theming
onto semantic tokens; the public overlay route must survive brand resolution; the
multi-consumer capability is forfeited.

**Option C — absorb the data model now, the engines later.** Bring
`Stage`/`Match`/`MatchSlot`/`MatchConnection` into Competition as local ULID tables in
Phase 2 and keep LanBrackets alive purely as a stateless bracket *calculator* called
once per stage generation. *Pros:* removes the silent-failure class and the polling
immediately, which is where the real pain is; caps Phase 2 scope; the format engines
can move at leisure. *Cons:* two systems still hold match structure during the overlap,
which is exactly the duplication being escaped; needs a reconciliation story nobody
wants to write twice; likely ends at Option B anyway, having paid for an intermediate
step.

**Recommendation: B**, with C as the pre-agreed fallback if Phase 2 slips. The
boundary is drawn through the middle of one aggregate — a competition's matches *are*
the competition — and everything downstream pays for that. The code that moves is
small, isolated behind four contracts, and covered by 34 Pest files that must move with
it. Write a new ADR superseding ADR-0002's LanBrackets paragraph; do not amend it in
place.

### D-CMP-02: Does Competition own a local `Match` row, or does match state stay remote?

**Context:** `app-modules/competition/SPEC.md` lists **Match** as owned, with result
state, scores, winner, forfeits. LanCore owns no such row: `match_result_proofs` and
`orchestration_jobs` each carry a bare `lanbrackets_match_id` string, and every read
of the match itself is `LanBracketsClient::getMatches()`. This decision is
*separable* from D-CMP-01 — it is the question of what happens if Option A wins.

**Option A — no local match row; read through to the bracket service.** *Pros:* one
writer, no reconciliation, no drift; matches LanCore exactly. *Cons:* `NextMatchProposer`,
`ConflictDetector`, the "my matches" page and the orchestration trigger all become
remote reads; LanCore's failure handling for these is literally
`catch (Throwable) { return; }`, meaning a bracket-service blip silently stops matches
from being orchestrated with no operator signal; GameOps' `MatchScheduled` trigger
would have to be derived from a poll rather than a state change.

**Option B — local `Match` rows as the source of truth, brackets computed into them.**
*Pros:* matches SPEC and ADR-0012's event flow (`MatchScheduled` becomes a real state
transition, not a polled inference); enables GameOps' idempotency key
(`competition_match_id`) to be a real ULID; queryable schedule and standings. *Cons:*
if LanBrackets stays external (D-CMP-01 Option A), two systems hold match structure and
must be reconciled after every bracket regeneration and every stage completion.

**Option C — local `Match` rows as a read-model projection, brackets remain
authoritative.** *Pros:* keeps single-writer semantics; local queries are fast;
projection can be rebuilt from webhooks. *Cons:* Competition then does *not* own result
state, contradicting ADR-0012's ownership split and its own SPEC; the "who wins on
conflict" question resurfaces on every correction and every replay.

**Recommendation: B**, and note that B plus D-CMP-01 Option A is an unstable
combination — the reconciliation cost is most of the absorption cost with none of the
benefit, which is itself an argument for D-CMP-01 Option B. If D-CMP-01 lands on
Option A, choose C and amend the Competition SPEC to say Competition owns result state
but not match structure.

### D-CMP-03: How far does "Competition" generalize beyond esports?

**Context:** Konventix is event-agnostic; a quiz, a pinball tournament and a hackathon
must fit. Measured evidence cuts both ways. In favour: LanBrackets' engines are fully
participant-agnostic — grep for `cs2|counter-strike|valorant|\bmap\b` across its `app/`
returns only `Collection::map()` and one unused doc comment; its rulesets are
`best_of`, `points_win/draw/loss`, `allow_draws`, `group_size`, `tiebreaker`. Against:
LanCore's `competitions` table has non-nullable-in-practice `game_id` and
`game_mode_id`; the duration estimator reads `game.avg_match_minutes`; the eligibility
rules include `has_steam_account_linked`; `CompetitionType::Race` exists and drives
nothing; `race_heat` and `final_stage` are stubs on both sides.

**Option A — Competition is game-optional.** Keep the model, make `game_id` a nullable
reference into GameOps, and keep every game-derived value (durations, default
eligibility) behind a null-safe fallback. *Pros:* smallest change; the format engines
already support it; a pinball tournament is a competition with a null game and a
round-robin stage. *Cons:* "competition with no game" is a shape nobody exercises until
the first non-LAN brand arrives, so it will rot; the estimator's defaults become the
only thing a quiz gets, and they are tuned for CS.

**Option B — introduce a `Discipline` abstraction.** Competition references a
`Discipline` (what is being contested); a Game is one kind of Discipline, supplied by
GameOps; a quiz round, a pinball table and a hackathon track are others, supplied by
Competition or a future module. *Pros:* the null case disappears; duration hints,
default eligibility and scoring shape hang off Discipline uniformly; GameOps stays
plugin-shaped because it *contributes* disciplines rather than being required.
*Cons:* a new abstraction with exactly one implementation at release, which is how
speculative generality usually starts; adds a lookup to every competition form.

**Option C — two competition kinds: paired and judged.** Accept that pairing-based
contests (elimination, RR, Swiss) and judged/scored contests (hackathon, quiz with a
jury, best-in-show) are structurally different, and model the second only when needed.
*Pros:* honest about what the bracket engines can and cannot do — no pairing algorithm
produces a hackathon result; avoids pretending a judged contest is a degenerate
bracket. *Cons:* two shapes in one module; the judged shape has no code today, so this
is a placeholder decision rather than a design.

**Recommendation: A now, with B's seam left open.** Make `game_id` nullable and route
every game-derived value through a single `GameProfile` read model (see D-CMP-04) so
that swapping its source for a `Discipline` later is one class, not a migration. Accept
C as a known gap and record it: a hackathon does not fit the bracket engines, and
pretending otherwise at release is worse than an explicit "not yet". Prove A before
release by creating one competition with a null game in the demo seed — otherwise the
generality is untested and therefore absent.

### D-CMP-04: Is the game catalogue GameOps-only, or shared?

**Context:** ADR-0012 and both SPECs place `Game` and `GameMode` in GameOps, with
Competition holding "a small read model for display". Measured, the catalogue is not
display data. `Game` carries `avg_match_minutes`, `avg_stage_minutes` and
`match_length_minutes` — read by Competition's `DurationEstimator` and the board
controller; `signup_rules` — the default eligibility tree Competition falls back to;
`logo_path`/`banner_path` — Competition falls back to them for its own branding; and
`GameMode.team_size`, which the competition form defaults from. Competition's
`effectiveMatchLengthMinutes()` resolves `Competition > GameMode > Game`. Orchestration
uses the catalogue for exactly two things: handler resolution and server filtering.
Games itself imports nothing from any other domain — it is the one genuinely clean
domain in LanCore.

**Option A — as specified: catalogue in GameOps, display read model in Competition.**
*Pros:* ADR-0012 stands; GameOps remains a self-contained plugin. *Cons:* the read
model is not "name and logo", it is name, logo, banner, three duration fields, a
default eligibility tree and a default team size — i.e. most of the table; when a brand
turns GameOps off, Competition loses its scheduling defaults and its eligibility
defaults, not just a logo. The SPEC's own open question already asks what happens to
display data when the module is off.

**Option B — catalogue in Competition, GameOps holds only the operational overlay.**
Competition owns `Game`/`GameMode` (they are what is being contested); GameOps owns a
`GameServerProfile` keyed by `game_id` carrying engine, default handler, provisioning
defaults. *Pros:* every consumer of duration and eligibility defaults is in
Competition, so no read model is needed; GameOps stays purely infrastructural and its
"off" state costs nothing but automation; aligns with D-CMP-03 Option B, where Game
becomes one Discipline. *Cons:* contradicts ADR-0012 and both SPECs as written; "a
module that owns games" is a slightly odd read for Competition until D-CMP-03's
Discipline framing lands.

**Option C — split by field.** Catalogue identity (name, slug, logo) in GameOps;
contest defaults (durations, team size, eligibility) in Competition, keyed by game
ULID. *Pros:* each field sits with its only consumer. *Cons:* one conceptual entity
across two tables in two modules, and the organizer has to know which screen sets which
half.

**Recommendation: B.** ADR-0012's split was written when the catalogue looked like
"name and description" (which is all GAM-F-001 requires); the fields added later —
`signup_rules`, three duration columns, logo/banner — all have Competition as their
only consumer, and the SPEC's phrase "a small read model for display" is not what the
data says. This is the one place where ADR-0012 should be amended rather than
implemented. If that is unacceptable, take A and make the read model explicit and
complete rather than "display only", and define the GameOps-off fallback for durations
and eligibility.

### D-CMP-05: How does signup eligibility work across module boundaries?

**Context:** LanCore's `SignupRuleRegistry` holds four rules —
`has_steam_account_linked` (Auth/Steam), `has_ticket_of_type` (Ticketing),
`has_seat` (Seating), `has_addon` (Ticketing) — stored as a JSON tree on the
competition, with a game-level default. In Konventix those four reach into Identity,
Registration, Resources and Registration respectively, all forbidden as direct model
reads by ADR-0004. There is no SRS requirement for any of it. Note the adjacent
`docs/inventory/policy-engine-options.md` covers *authorization*; this is *eligibility*,
and the two should not be merged by accident.

**Option A — each rule is a contract implemented by the owning module.** Competition
declares a `EligibilityRule` interface and a registry; Registration, Identity and
Resources each register their own rules. *Pros:* no cross-module reads; each module
owns the semantics of its own facts; adding a rule is a change in the module that has
the data. *Cons:* a synchronous cross-module call at evaluation time, which is a
contract of a different kind than events — needs an explicit ADR-0004 carve-out for
query contracts, or it becomes the precedent everyone cites for the next shortcut.
**Option B — Competition maintains read models of the facts it gates on**
(`admission_status`, `held_resource_types`, `linked_social_providers`), populated from
events. *Pros:* pure ADR-0004; evaluation is a local query; replayable. *Cons:*
Competition accumulates a projection of half the platform; eventual consistency means a
participant who just bought an add-on may be told they are ineligible; every new rule
needs a new projection and a backfill.

**Option C — drop the rule engine; express eligibility as a required Admission or
RegistrationType.** *Pros:* no engine, no projections; leans on ADR-0005's existing
distinction. *Cons:* loses genuinely used capability — `has_steam_account_linked` is
not expressible as an admission, and it is exactly the check that stops a CS signup
from failing at match-configuration time.

**Recommendation: A**, with the query-contract carve-out written into the ADR rather
than left implicit, and with the rule tree stored as a typed value object rather than a
raw JSON blob (ADR-0009). Keep `has_steam_account_linked` as the proof case: it is the
one rule whose failure mode is a broken match rather than a refunded ticket.

### D-CMP-06: Do deployments get reused between rounds? *(ADR-0012 open question 1)*

**Context:** No implementation exists to reason from — LanCore has no pooling at all,
only `GameServer.status` flipping between `available` and `in_use`. So this is a
green-field call. Real constraint: at an SXLan-sized event a tight schedule can put
consecutive rounds minutes apart, and provisioning is the slowest step in the pipeline.

**Option A — fresh deployment per match.** *Pros:* no state bleeds between matches —
no leftover map, no lingering RCON session, no players still connected from the
previous match; the failure blast radius is one match. *Cons:* provisioning latency
lands on the critical path of every match; at 8 concurrent matches × 6 rounds that is
48 provisions where 8 would do; higher provider cost and higher chance of hitting a
rate limit at the worst moment.

**Option B — reuse within a competition, destroy at competition end.** A deployment
returns to its pool after `MatchFinished` and is claimed by the next match of the same
game and mode. *Pros:* provisioning happens once per pool slot; the schedule is not
hostage to provider latency. *Cons:* requires an explicit reset step between matches
(the handler's `configure` must be trusted to fully reset state, which for TMT2 means
RCON init commands); a corrupted server poisons every subsequent match on it; needs a
"drain" concept for taking a bad server out of rotation.

**Option C — reuse with a health gate.** Option B plus a mandatory `getState`/health
check before a deployment is re-claimed; failing that check destroys and re-provisions.
*Pros:* keeps B's latency win while bounding the poison case. *Cons:* the health check
is only as good as the handler's implementation, and there is exactly one handler.

**Recommendation: C**, because it is B with a defined failure exit rather than a
different strategy, and because the reset step is needed under B anyway. Make the
choice per game (`GameServerProfile.reuse_policy`), not global — a title with clean
RCON reset behaves differently from one without — and default new games to A so that an
untested title cannot silently poison a bracket. This resolves ADR-0012 Q1; record it
in the ADR rather than only in the module SPEC.

### D-CMP-07: What happens when provisioning fails? *(ADR-0012 open question 2)*

**Context:** LanCore's behaviour is the baseline to beat, and it is poor. If
`SelectServerForMatch` returns null the job is marked `Failed` with a message,
`attempts` is incremented, and **nothing tells Competition or the tournament desk**.
The only signal is an admin noticing a red row on `/orchestration-jobs`. The
Competition SPEC already names the requirement: "a stalled match with no signal is the
worst outcome."

**Option A — retry with backoff, then fail loudly.** N attempts on the queue, then emit
`DeploymentProvisioningFailed`, which Competition turns into a flagged match on the
tournament-desk view. *Pros:* simple; handles the common case (transient provider
error); the event satisfies the "must reach Competition" requirement. *Cons:* a
persistent provider outage burns N × backoff before anyone is told, which at a live
event is the wrong order.

**Option B — retry once, then fall back to a static pool server.** Same as A but with a
manually maintained reserve pool as the second attempt. *Pros:* the match plays; static
inventory is exactly what LanCore already models, so the fallback costs no new concept.
*Cons:* two provisioning paths to test; the reserve pool is only as good as whoever
maintained it, and a stale reserve fails at the worst moment; hides provider problems
until the reserve is exhausted.

**Option C — fail fast to the tournament desk with a manual-server option.** One
attempt; on failure emit the event immediately and offer the desk a form to enter
connection details for a hand-run server, which the handler then configures. *Pros:*
matches how a LAN actually degrades — a human with a spare box is the real fallback;
shortest time to a human being told; keeps GameOps honest about what it cannot do.
*Cons:* requires a desk UI at release; a transient blip becomes a human interruption.

**Recommendation: A + C.** Bounded retries (2, short backoff) then immediate
`DeploymentProvisioningFailed`, and a desk action to supply a manual server that reuses
the existing `MatchHandler::configure` path. Skip B — a reserve pool that is only
exercised during incidents is a reserve pool that does not work during incidents.
Whatever is chosen, the non-negotiable part is the event: GameOps must never leave a
match stalled without telling Competition.

### D-CMP-08: How is a running match session known to be alive? *(ADR-0012 open question 3)*

**Context:** LanCore has no liveness concept whatsoever. `healthCheck()` on
`MatchHandlerContract` is called exactly once, *before* deploy, and for TMT2 it is a
login call — it proves the TMT2 API is reachable, not that the match is running. Once a
job reaches `Active` the only thing that can move it is a TMT2 `MATCH_END` webhook or
LanBrackets' `match.result_reported`. If TMT2 dies mid-match the job stays `Active`
forever and the server stays `in_use` forever; `ForceReleaseGameServer` exists as the
manual escape hatch, which is the tell.

**Option A — GameOps polls `MatchHandler::getState()` on a schedule.** *Pros:* works
with any handler that can answer; no inbound endpoint to secure; detects both handler
death and match-never-started. *Cons:* polling cost scales with concurrent matches; the
poll interval is the detection latency; a handler with an expensive state call makes
this painful.

**Option B — handler heartbeats inbound.** The handler posts a periodic signed
heartbeat; a missed window marks the session failed. *Pros:* near-real-time detection;
no polling load. *Cons:* requires the handler to support it — TMT2's webhook set would
need extending, which means a change in a system Konventix does not own; and the
current TMT2 endpoint is unauthenticated, so this must not ship before signing does.

**Option C — provider status as the liveness source.** Ask the `GameServerProvider`
whether the server is up. *Pros:* provider-agnostic and cheap. *Cons:* answers the
wrong question — a healthy server running no match looks identical to a healthy server
running a match, which is precisely the failure being hunted.

**Recommendation: A**, with a per-game poll interval and a hard session timeout derived
from `effectiveMatchLengthMinutes()` (which already resolves
Competition > GameMode > Game and exists today) plus a generous multiplier. On timeout,
emit `MatchSessionFailed`; Competition flags the match for the desk. Treat B as a later
optimisation, gated on the TMT2 webhook being signed first. Rejecting C matters: it is
the tempting cheap answer and it detects nothing.

### D-CMP-09: Does a handler-reported score write the result, or propose it?

**Context:** Both SPECs name `MatchResultReported` as a *report*, and state that the
ownership split "survives only if this event never writes result state" — but the
question of what Competition then does with it is listed as open. LanCore's answer is
implicit and inconsistent: `HandleTmt2Webhook` reports the TMT2 score straight to
LanBrackets with no human in the loop (ORC-F-013), while the participant path
(COMP-F-008) requires a screenshot and creates a `MatchResultProof` with an
`is_disputed` flag that nothing ever reads. So today an automated score is trusted
absolutely and a human-submitted one is evidence-gathered and then also trusted.

**Option A — handler report writes the result directly.** *Pros:* brackets advance
without human latency, which at a 6-round tournament is the difference between finishing
on schedule and not; matches LanCore's current automated behaviour. *Cons:* a handler
bug or a griefed match writes a wrong result into standings, and correcting it means
re-emitting `MatchFinished` — which GameOps must not act on twice (already flagged as
an open question in the Competition SPEC).

**Option B — handler report proposes; the desk confirms.** *Pros:* a human always owns
the sporting truth; disputes have somewhere to live; `is_disputed` finally means
something. *Cons:* a confirmation queue at a live event is a person, and if that person
is busy the whole bracket stalls; this is the failure mode that made automation
attractive in the first place.

**Option C — auto-confirm after a challenge window.** The report writes provisionally
and becomes final after N minutes unless a participant or referee challenges. *Pros:*
no human on the critical path in the normal case; disputes still possible; the challenge
window is naturally the gap before the next round. *Cons:* two result states to model
(provisional, final) and two events, or one event with a flag; standings must be
readable during the provisional window and must handle a late reversal.

**Recommendation: C**, with the window configurable per competition and settable to
zero (= Option A) for competitions that want pure automation. Model the correction path
explicitly as a distinct `MatchResultCorrected` event rather than a second
`MatchFinished` — that directly answers the Competition SPEC's "GameOps must not tear a
server down twice" concern, since GameOps subscribes to `MatchFinished` only.

### D-CMP-10: Does GameOps ship ADR-0012's provisioning design, or LanCore's static inventory first?

**Context:** This is the decision the rest of GameOps rests on. ADR-0012 reads as a
port and is not one: `Deployment`, `MatchSession`, `GameServerProvider`,
`MatchHandlerResolver`, `DeploymentStrategyResolver`, "provisioning", Pelican, and
`floor(max_participants / team_size / 2)` all return **zero grep hits** across LanCore.
What exists is a `game_servers` table an admin fills in by hand, a selection query with
`lockForUpdate()`, an `OrchestrationJob` state machine whose transitions are defined but
never enforced, one handler that cannot currently match any game because it probes a
non-existent column, and an unauthenticated webhook. Phase 2's definition of done says
"tournaments run end-to-end", not "servers are provisioned dynamically". Also relevant:
`lessons-from-lancore.md` credits Orchestration with clean event-driven boundaries —
true of its *listeners*, but its imports reach into Competition, Games, Api, Newsletter
and Shop, so the praise is narrower than it reads.

**Option A — build ADR-0012 in full for Phase 2.** *Pros:* the ADR is accepted and the
module SPEC is written against it; building the abstraction later means migrating
`game_servers` rows into `deployments`; dynamic provisioning is the actual
differentiator over a spreadsheet of IPs. *Cons:* it is a green-field build of a
provider abstraction, a pool sizer, four provisioning modes, a deployment-strategy
resolver, and a Pelican driver — none of which has ever run — landing in the same phase
as Registration, Admission, Commerce and Staffing; and three of its behaviours (D-CMP-06,
-07, -08) are still open.

**Option B — port the static inventory, define the contracts, defer the drivers.**
Ship `GameServer` + `MatchSession` + `MatchHandler` (TMT2) with a `StaticPoolProvider`
implementing `GameServerProvider` against hand-entered rows. *Pros:* Phase 2 gets
working tournaments on the servers SXLan already runs; the contracts exist from day one
so the Pelican driver is additive; `Deployment` can be introduced as the provisioned
subtype without moving the static rows; the open questions get answered against running
code rather than in the abstract. *Cons:* the ADR's headline capability slips past
release; a static pool means someone maintains the list, which is the status quo.

**Option C — skip the static path; provisioning only.** *Pros:* one code path, no
legacy shape to carry. *Cons:* a brand with three permanent servers and no provider
cannot use the module at all, which is most brands; and it bets Phase 2 on an untested
Pelican integration.

**Recommendation: B.** Declare all four contracts exactly as ADR-0012 names them —
the module TODO already puts this before the migrations, correctly — and implement
`StaticPoolProvider` first so the abstraction is exercised by real traffic before the
Pelican driver arrives. This does not contradict ADR-0012; it sequences it, and it is
the only option under which D-CMP-06 through D-CMP-08 are answered by observation
rather than by guess. Fix the two defects in the port regardless of which option wins:
sign the handler webhook, and enforce `MatchSessionStatus::allowedTransitions()`.

# Competition Implementation Plan (0.9.0, sub-project B)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** SXLAN's tournament programme runs end to end on Konventix — an organizer creates competitions and disciplines, participants enter as teams, check in, play, report results and dispute them, and the desk and the public see the brackets.

**Architecture:** One module, Competition, extending what slice 10 already built (11 models, 9 Actions, the bracket and standings engines). Eight tasks, one per GitLab issue except #301 which splits into desk and public pages. Nothing here depends on GameOps (D-AGD/ADR-0062: the discipline, not the game, carries contest defaults), and the module keeps its one-way relationship with GameOps through `MatchScheduled`/`MatchFinished`.

**Tech Stack:** Laravel 12 modules (`app-modules/competition`), Pest on Postgres, spatie/laravel-data + typescript-transformer, Inertia v3 + Vue 3 + shadcn-vue, Wayfinder, Laravel Pennant.

**Spec:** `docs/superpowers/specs/2026-09-18-competition-design.md` — **the binding authority, and unusually complete**. Each task below names the sections that define it; read them as your requirements, not as background. Decisions D-CMP-15 … D-CMP-25.

**Issues:** #296 (1), #297 (2), #298 (3), #299 (4), #300 (5), #301 (6+7), #302 (8). Each task closes its issue.

## Global Constraints

- **This plan runs in a git worktree** at `worktrees/competition` on branch `competition-2026-09-23`. Tooling there is not Sail — read `.superpowers/sdd/WORKTREE-TOOLING.md` first: a relative `vendor` symlink plus a one-off `docker run --entrypoint php … sail-8.5/app` for pint and phpstan. **npm does not work in the worktree** (no `node_modules`), so tasks 6 and 7 — the Vue-heavy ones — either run in the main checkout or begin with a real `npm ci`; the task text says which.
- **No local test runs** (operator instruction). Write the tests; the GitLab pipeline on `develop` executes them. `pint` and `phpstan` DO run locally and are expected before every commit.
- **The format-on-edit hook does not fire in a worktree.** Run pint by hand. (In the main checkout it does fire, and it strips unused imports — add a `use` in the same edit as its first use.)
- Commit on `competition-2026-09-23`; the controller merges to `develop`. Do not push, do not rebase, do not amend.
- `brand_id` never mass-assigned; `BelongsToBrand` stamps it; admin-host reads use `withoutBrandScope()->where('brand_id', …)` (ADR-0029).
- ULIDs, no FK across module boundaries (ADR-0014); real FKs inside Competition are fine. Cross-module `use` only for `Konventix\<Module>\Events\*` and Platform host services.
- Events are `final readonly class`, ULIDs and scalars only (ADR-0004). **`MatchFinished` fires exactly once per match** (D-CMP-12) — a correction emits `MatchResultCorrected`; GameOps subscribes to the former only, and `GameOpsBoundaryTest` enforces that Competition imports no GameOps class.
- Instants anchored `CarbonImmutable::parse($v)->utc()` before assignment to a datetime cast.
- Participant copy **German**, operator console copy **English**.
- `Data` objects with `rules()` (ADR-0009); an Action per write (ADR-0015); listeners registered explicitly.
- Read before touching matching paths: `.ai/rules/{models,src,data,js,pages,providers,tests}.md`.
- Commit messages: one plain sentence in the repo's style, ending with:
  `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>`
  `Claude-Session: https://claude.ai/code/session_01Pxs1vGJjmPbCePd3MDbLU6`

## What already exists (verified 2026-09-23, do not rebuild)

- **Models** (11): `Competition`, `Discipline`, `Stage`, `CompetitionMatch`, `MatchSlot`, `MatchConnection`, `Entrant`, `CompetitionTeam`, `CompetitionTeamMember`, `Standing`, `EligibleRegistration`.
- **Actions** (9): `EnterCompetition`, `GenerateBracket`, `AdvanceBracket`, `PairNextSwissRound`, `ScheduleMatch`, `RecordMatchResult`, `CorrectMatchResult`, `CalculateStandings`, `ProjectEligibleRegistration`.
- **Enums**: `DisciplineKind` (VideoGame/Tabletop/Quiz/Judged), `EntrantState` (registered/checked_in/withdrawn/disqualified), `MatchState` (pending/scheduled/live/finished/cancelled), `Permission` (6 cases).
- **Events**: `EntrantRegistered`, `MatchScheduled`, `MatchFinished`, `MatchResultCorrected`.
- **Tests** (6 files) including `GameOpsBoundaryTest` and the bracket/standings maths suites — **do not weaken them**.
- **Missing and expected by the spec**: `ParticipantProfile`, `CompetitionStatus`, everything in tasks 2–8, and all routes but `routes/web/competition.php`.

---

### Task 1: Lifecycle and admin CRUD (#296)

**Requirements:** design **§4** in full, plus §3's D-CMP-15 row and §7.1 (the format moves to the stage).

**Files:** `src/Enums/CompetitionStatus.php`; migrations adding `status`, `starts_at`, `max_entrants`, `description`, `rules`, the check-in and result knobs to `competitions` and **dropping `competitions.format`**; `src/Actions/{PublishCompetition,CloseSignup,StartCompetition,FinishCompetition,CancelCompetition}.php`; `src/Exceptions/IllegalCompetitionStateTransitionException.php`; `src/Data/{CompetitionData,DisciplineData}.php`; `src/Http/Controllers/Admin/{CompetitionAdminController,DisciplineAdminController}.php`; `routes/admin/competition.php`; Vue under `resources/js/pages/admin/competitions/` and `.../disciplines/`; `tests/CompetitionLifecycleTest.php`, `tests/DisciplineAdminTest.php`.

**Interfaces produced:** `CompetitionStatus::{Draft,Published,SignupClosed,Running,Finished,Cancelled}` with `allowedTransitions(): array` consulted by every lifecycle Action; events `CompetitionPublished`, `SignupOpened`, `SignupClosed(competitionId, eventId, brandId, entrantCount)`, `CompetitionStarted`, `CompetitionFinished`, `CompetitionCancelled` — all ULIDs and scalars. **Sub-project C consumes `CompetitionPublished`/`CompetitionRescheduled`/`CompetitionCancelled` and `competitions.starts_at` for its programme half**, so keep those names and that column.

**Tests:** every legal transition; an illegal one throws; `Running` refuses while any entrant is `incomplete` (§5.4) and while no stage exists; `SignupClosed` carries the entrant count; dropping `format` does not break `GenerateBracket` (it reads the stage).

**Worktree note:** PHP-only except the two admin pages — write the Vue, skip `npm run build`, and say so in your report; the controller builds in the main checkout before merge.

---

### Task 2: Signup — teams, captains, invite codes, the interested list (#297)

**Requirements:** design **§5** in full (§5.1 gate, §5.2 teams/captains/codes, §5.3 interested list, §5.4 incomplete teams, §5.5 withdrawal and disqualification).

**Files:** migrations for `competition_team_members.role`, `competition_teams.invite_code`, `competition_interests`, and `EntrantState::Incomplete`; `src/Actions/{JoinTeamByCode,RegenerateInviteCode,ExpressInterest,WithdrawInterest,SyncEntrantCompleteness,FillIncompleteEntrant,DeclineIncompleteEntrant,WithdrawEntrant,DisqualifyEntrant}.php`; `src/Models/ParticipantProfile.php` + its projection from Identity's `UserProfileUpdated`; `src/Http/Controllers/{MyTournamentsController,TeamJoinController,InterestController}.php`; `routes/web/` additions; Vue under `resources/js/pages/competition/my/`; `tests/SignupTest.php`, `tests/IncompleteEntrantTest.php`.

**Interfaces produced:** `EntrantState::Incomplete`; `EntrantRegistered`/`EntrantWithdrawn` gain `team_id`, `member_user_ids`, `reason` (**additive** — `MatchScheduled` also gains `member_user_ids` per side, which GameOps needs later); invite codes are 8 characters excluding `0/O/1/I`.

**Tests:** the issue's three acceptance criteria verbatim — a 5v5 team of three registers, is `incomplete`, and is filled from the interested list at close; a duplicate team registration hits the partial index; no module import crosses the boundary (`ParticipantProfile`, never Identity's `User`).

---

### Task 3: Check-in (#298)

**Requirements:** design **§6** in full.

**Files:** migration for the per-competition check-in mode (`none` / `self_service` / `desk`) plus the "requires door presence" flag and the self-service window; `src/Enums/CheckInMode.php`; `src/Actions/{CheckInEntrant,UndoCheckIn}.php`; the presence read model projected from Admission's `AdmissionCheckedIn` + `AdmissionHolderAssigned`; desk list controller and Vue; `tests/CheckInTest.php`.

**Interfaces produced:** `CheckInMode`; `Permission::CheckInEntrants`; a presence read model Competition owns (never a query into Admission).

**Tests:** each of the three modes; the door-presence requirement refusing a check-in for someone the door has not seen; the self-service window's edges; undo.

---

### Task 4: Formats, stages and the leaderboard (#299)

**Requirements:** design **§7** in full (§7.1 format on the stage, §7.2 leaderboard, §7.3 multi-stage modelled-not-built, §7.4 match sides, §7.5 seeding and regeneration).

**Files:** migrations for `competition_score_entries`, `stages.entry_source`, `stages.advance_count`; `src/Enums/StageFormat.php` (five bracket formats plus `leaderboard`); `src/Data/LeaderboardSettings.php`; `src/Standings/LeaderboardStandingsCalculator.php`; `src/Seeding/SeedSource.php` + `RandomSeedSource`, `ManualSeedSource`; `src/Actions/{SubmitScoreEntry,VerifyScoreEntry,SeedStage,RegenerateBracket}.php`; `tests/LeaderboardTest.php`, `tests/SeedingTest.php`.

**Interfaces produced:** `StageFormat`; `SeedSource` (the seam §7.3 names, so a later multi-stage competition plugs in `PreviousStageSeedSource` without touching callers); `LeaderboardStandingsCalculator` alongside the existing `CalculateStandings`.

**Tests:** a leaderboard stage with heats ranks correctly; regeneration is allowed until the first result and refused after; manual seed order survives regeneration; the existing `BracketMathematicsTest`/`StandingsMathematicsTest` still pass untouched.

---

### Task 5: Results, proofs, disputes, corrections, history (#300)

**Requirements:** design **§8** in full (§8.1 the two knobs, §8.2 head-to-head, §8.3 scored entries, §8.4 disputes, §8.5 the cascade, §8.6 history, §8.7 proofs).

**Files:** migrations for `match_result_reports`, `competition_match_history`, proof columns; `src/Actions/{ReportMatchResult,ConfirmMatchResult,DisputeMatchResult,ResolveDispute}.php`; extend `CorrectMatchResult` with the cascade; `src/Enums/MatchHistoryKind.php`; proofs through Platform's media library (ADR-0035); `tests/ResultReportingTest.php`, `tests/CorrectionCascadeTest.php`.

**Interfaces produced:** `MatchHistoryKind` with the kinds §8.6 lists — `scheduled`, `rescheduled`, `checked_in`, `reported`, `confirmed`, `disputed`, `resolved`, `recorded`, `corrected`, `voided_by_correction`, `cancelled`, **plus `session_live` and `session_failed` reserved for GameOps**.

**Tests:** both sides agreeing finalises; disagreement opens a dispute; an organizer resolves it with a mandatory comment; a correction voids downstream results and recomputes standings; **`MatchFinished` is not re-emitted for a voided match replayed** (the `finished_event_emitted_at` guard, D-CMP-12) — this is the test GameOps depends on.

---

### Task 6: The tournament desk (#301, first half)

**Requirements:** design **§9** in full, plus D-CMP-21 (scheduling is `competitions.starts_at` plus a desk-set `scheduled_at` per match with a per-round bulk action; the board and the estimator stay #117).

**Files:** `src/Http/Controllers/Admin/TournamentDeskController.php`; `routes/admin/` additions; Vue under `resources/js/pages/admin/competitions/desk/`; `tests/TournamentDeskTest.php`.

**Vue-heavy: run this task in the MAIN checkout, not the worktree** — it needs `npm run build`, `lint` and `types:check`. The controller will tell you which checkout when dispatching.

**Tests:** seed order set and regenerated; generate and reset a stage; per-round bulk scheduling; the desk sees open disputes.

---

### Task 7: Public and participant pages, and the bracket (#301, second half)

**Requirements:** design **§10** in full, plus D-CMP-22 (port LanBrackets' canvas renderer onto semantic tokens, with a **table view as the accessible alternative**, ADR-0055; public pages poll, the desk broadcasts later).

**Files:** `src/Http/Controllers/{CompetitionPageController,MatchPageController,TeamPageController,EventTournamentsController}.php`; `routes/web/` additions; Vue: the ported canvas bracket, its table alternative, the event tournament index, competition/match/team pages; `tests/PublicPagesTest.php`.

**Vue-heavy: MAIN checkout.** The canvas port is the single largest piece of frontend work in this plan — if it runs past what one task should hold, report DONE_WITH_CONCERNS and the controller will split it rather than let it sprawl.

**Tests:** each public page renders for a published competition and 404s for a draft; the table view carries the same pairings as the canvas; polling while `Running` is present and absent otherwise.

---

### Task 8: Capabilities, roles, observability, GDPR, definition-of-done (#302)

**Requirements:** design **§11** (capabilities and the Tournament Admin / Tournament Desk split), **§12** (the full event list — reconcile what tasks 1–7 actually emitted against it and fix the divergence), **§13** (observability, GDPR, tests), and ADR-0059.

**Files:** `src/Enums/Permission.php` gains `ManageSchedule`, `CheckInEntrants`, `ViewEntrantContacts`; `defaultRoles()` splits; `src/Metrics/CompetitionMetrics.php` + recorders; a panel in `contrib/observability/src/`; `src/Gdpr/CompetitionGdprHandler.php`; `SPEC.md` and `TODO.md` rewritten; `docs/operations.md` paragraph; `tests/CompetitionActivationTest.php` and the two definition-of-done flow tests §13 names.

**Tests:** `permissions:sync` picks the three new capabilities up; both default roles grant what §11 says; with `competition` off for a brand there are no routes, no nav, no listeners and no errors; the two end-to-end flows run.

---

## Ordering and parallelism

Tasks 1 → 2 → 3 → 4 → 5 are a chain: each builds on the previous task's schema and Actions. Tasks 6 and 7 both need 1–5. Task 8 needs everything.

**Task 1 is the gate for sub-project C's second half** — C's competition read model waits on `CompetitionPublished` and `competitions.starts_at`. Tell the controller the moment Task 1 merges.

## Self-review against the spec

| Spec section | Task |
|---|---|
| §4 lifecycle, §7.1 format on the stage | 1 |
| §5 signup in all five sub-sections | 2 |
| §6 check-in | 3 |
| §7.2–7.5 leaderboard, multi-stage seam, sides, seeding | 4 |
| §8 results, proofs, disputes, cascade, history | 5 |
| §9 desk, D-CMP-21 scheduling | 6 |
| §10 public pages, D-CMP-22 bracket + table | 7 |
| §11 capabilities, §12 events reconciliation, §13 observability/GDPR/DoD | 8 |
| §2 evidence, §3 decisions, §14 deviations | context for all tasks; §14 is where an implementer records what it found |

**Known gap, deliberately carried:** §12's event list is written ahead of the code, so tasks 1–7 will emit a set that does not match it exactly. Task 8 reconciles rather than every task guessing — which is why §12 is Task 8's requirement and not Task 1's.

**Not in this plan:** the Competition Board (#117, post-1.0), match chats (#116), the Eventula tournament import (D-CMP-25 — there is nothing worth importing), blossom pairing (D-CMP-24 keeps greedy), and everything GameOps (#114/#115/#179, release 0.10+).

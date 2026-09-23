# Programme Competitions Implementation Plan (0.9.1, #331 — the timetable's half two)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An organizer picks two of the event's published tournaments in the programme editor; they appear in the public programme at their Competition-owned start times as open-ended blocks, and moving, cancelling or unpublishing them in Competition changes the programme without anyone touching the editor.

**Architecture:** Competition gains one contract event (`CompetitionUnpublished`), one additive field (`slug` on `CompetitionPublished`) and one backfill (`competition:announce-competitions` emitting the narrow `CompetitionAnnounced`). Agenda gains two tables — `agenda_competitions`, a read model projected only from those events, and `agenda_competition_entries`, the editor's selection — and teaches `ProgrammeBuilder` and `NowNext` to place a selected competition beside the items. Agenda never reads Competition's tables and never writes a start time (D-AGD-02, D-AGD-03).

**Tech Stack:** Laravel 12 modules (`app-modules/competition`, `app-modules/agenda`), Pest on Postgres, spatie/laravel-data + typescript-transformer, Inertia v3 + Vue 3 + shadcn-vue, Wayfinder, Laravel Pennant.

**Spec:** `docs/superpowers/specs/2026-09-23-timetable-design.md` — **§4.5**, §5, §6, §8, §10 item 7 and decisions **D-AGD-01, D-AGD-02, D-AGD-03** (D-AGD-08 and D-AGD-09 bind too). `docs/superpowers/specs/2026-09-18-competition-design.md` **§12** and the §14 entry **"Unpublishing a competition tells nobody"**, which makes `CompetitionUnpublished(competitionId, eventId, brandId, unpublishedAt)` a precondition of this work. Issue **#331** (its three acceptance boxes are the definition of done).

## Global Constraints

- Work happens in the MAIN checkout `/home/mawiguko/git/konventix/konventix-poc` on `develop` (no worktree). Commit on `develop`; do not push, rebase or amend.
- **No local test runs** (operator instruction): write the tests; the GitLab pipeline runs them. `pint` and `phpstan` run locally before every commit, and for the Vue tasks `npm run lint`, `format`, `types:check` and `build` — all via `vendor/bin/sail`.
- Expected values in tests are derived by hand, with the derivation in a comment beside the assertion (row indexes, spans, wall-clock conversions).
- The format-on-edit hook strips unused imports: add a `use`/`import` in the same edit as its first use.
- ULIDs (ADR-0014); `brand_id` never mass-assigned — projections use `forceFill(['brand_id' => …])` and read with `withoutBrandScope()->where('brand_id', …)` (the `ProjectEventSummary` shape). `competition_id` in Agenda is a plain ULID with **no FK**; `track_id` on the selection is a real in-module FK, `nullOnDelete`.
- Events `final readonly class`, ULIDs and scalars only (ADR-0004), dispatched via `DB::afterCommit()` when raised inside a transaction. Instants on the wire are ISO-8601 strings; datetime parameters are typed `?CarbonInterface` (#312), and `Competition`'s `@property Carbon` lines lie (`Date::use(CarbonImmutable::class)`).
- Cross-module `use` only for `Konventix\Competition\Events\*`, `Konventix\Event\Events\*` and Platform host services (`ModuleGate`, `OperatorContext`). Agenda links to a competition's page by **route name string** (`competition.page`), never by importing Competition code; `tests/Feature/HubComponentBoundaryTest.php` still forbids `NowNextCard.vue` importing anything outside `@/components/ui/*`, `@/lib/*` and Agenda's own generated types.
- Participant copy German, console copy English (ADR-0001). Listeners registered explicitly with `Event::listen` in the owning provider (discovery is off). Console routes go through `AgendaAdminController::summary()` — operator-scoped via `OperatorContext`, module-gated via `ModuleGate::isActiveForBrand('agenda', …)` — and open with `Gate::authorize(Permission::ManageAgenda->value)`.
- Inertia tests call `$this->app->forgetScopedInstances()` between two full-page requests in one test; console tests put the operator's admin host in the URL (`.ai/rules/tests.md`).
- Never `Event::fake()` without arguments.
- Read before touching matching paths: `.ai/rules/{app-modules-src,src,models,data,listeners,providers,js,pages,resources,tests,observability}.md`.
- Commit messages: one plain sentence in the repo's style, ending with:
  `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`
  `Claude-Session: https://claude.ai/code/session_01QPCmPxfGD9UDAAUGKgwEbN`
- New post-rollout backfills are listed in `docs/operations.md`'s main backfill list (Task 6).

## What already exists (verified 2026-09-23 on develop 8b434a25 — do not rebuild)

- **Agenda, half one, shipped**: models `AgendaItem`, `Track`, `Location`, `EventSummary` (no factory; rows only via `Listeners\ProjectEventSummary`); `Enums\ItemState`, `Enums\Permission::ManageAgenda`; Actions `SaveAgendaItem`, `SaveTracks`, `SaveLocations`, `PublishAgendaItem`, `CancelAgendaItem`; Data `AgendaItemData`, `TrackData`, `LocationData`, `ProgrammeCellData` (`ofItem(AgendaItem, string $timezone, int $rowSpan = 1)`), `ProgrammeRowData`, `ProgrammeDayData`, `NowNextCardData`; `Programme\ProgrammeBuilder::forEvent(string $eventId, string $brandId, array $days, string $timezone): array` and `Programme\NowNext::forEvent(string $eventId, string $brandId, CarbonImmutable $at): array{now, next}` (with `OPEN_ENDED_RUNS_FOR_MINUTES = 120`); `Hub\NowNextCard`; `Http\Controllers\ProgrammeController` (`/events/{slug}/programm`, name `agenda.programme`) and `Http\Controllers\Admin\AgendaAdminController` (`edit`, `update`, `storeItem`, `updateItem`, `publishItem`, `cancelItem`, `destroyItem`, private `summary()`); Vue `pages/admin/agenda/Edit.vue` (**755 lines — anything new is an extracted component**), `pages/agenda/Programme.vue`, `components/{ProgrammeGrid,ProgrammeList}.vue`, `components/programmeTime.ts`, `components/hub/NowNextCard.vue`; metric `agenda_items_published_total{brand}`; seven test files incl. `tests/Helpers.php` (`agendaItemData()`, `agendaPublishedEvent()`, `agendaPublishedItem()`, `agendaAdminOperator()`; `agendaDay()` is declared in `ProgrammeBuilderTest.php`).
- **SPEC/TODO**: `app-modules/agenda/SPEC.md` already describes half two under *Owns*, *Consumes* and *Capabilities*; `app-modules/agenda/TODO.md` § *Next* holds "The competition half" with the `CompetitionUnpublished` precondition — this plan closes that item.
- **Competition**: `Competition` has `starts_at`, `slug` (unique per brand), `status` (`CompetitionStatus`: `Draft|Published|SignupClosed|Running|Finished|Cancelled`, `isPubliclyVisible()` = not draft) and `published_at` (cleared by unpublish, kept by cancel). Events: `CompetitionPublished(competitionId, eventId, disciplineId, brandId, name, status, ?startsAt, at)` from `PublishCompetition`; `CompetitionRescheduled(competitionId, eventId, brandId, ?previousStartsAt, ?startsAt, at)` from `UpdateCompetition` for a non-draft only; `CompetitionCancelled(competitionId, eventId, disciplineId, brandId, status, at)` from `CancelCompetition` (reachable from `Draft` too). `UnpublishCompetition` (`Published → Draft`, clears `published_at` and `signup_opened_at`) **emits nothing**. `ReopenSignup` (`SignupClosed → Published`) emits `SignupOpened` only. **Nothing consumes any of the three lifecycle events yet** (grep). All three are constructed with named arguments at their single emit site.
- **No competition backfill or replay command exists** — Competition's only command is `SignupWindowTickCommand` (`competition:signup-tick`). The 7c pattern to copy is `identity:announce-social-identities` (`Identity\Console\AnnounceSocialIdentitiesCommand`, `Identity\Events\SocialIdentityAnnounced`, `identity/tests/AnnounceSocialIdentitiesCommandTest.php`) and its sibling `registration:announce-standing` (`RegistrationStandingAnnounced`, which carries `confirmed: bool` so a consumer can *unlearn*).
- **Public competition page**: route `competition.page`, `/events/{slug}/tournaments/{competition}` with `{competition}` the competition's slug (`routes/web/competition.php`).

---

### Task 1: `CompetitionUnpublished`, and the slug on `CompetitionPublished`

**Requirements:** competition design §14 "Unpublishing a competition tells nobody" (binding: exact name and payload); §12 (events are additive, ULIDs and scalars); timetable design §4.5 (the read model carries `slug`).

**Files:** `app-modules/competition/src/Events/CompetitionUnpublished.php` (new); `src/Actions/UnpublishCompetition.php`; `src/Events/CompetitionPublished.php` + `src/Actions/PublishCompetition.php` (add `slug`); `app-modules/competition/SPEC.md` (the *Public contract* table gains the row, the "owed" sentence under it goes); `app-modules/competition/TODO.md` (tick "`CompetitionUnpublished` before Agenda's competition half ships"); `docs/superpowers/specs/2026-09-18-competition-design.md` (§14 entry and the reconciliation row for `CompetitionUnpublished` each gain one dated line: "Emitted from 0.9.1, #331"); `tests/CompetitionLifecycleTest.php`.

**Interfaces produced:**
- `final readonly class CompetitionUnpublished { string $competitionId, string $eventId, string $brandId, string $unpublishedAt }` — emitted by `UnpublishCompetition::handle()` inside its existing `DB::transaction`, through `DB::afterCommit()`, after `transition()` succeeds.
- `CompetitionPublished` gains `public string $slug`, placed after `$name` (the one emit site uses named arguments, so nothing else moves).

**The boundary that bites:** the event fires only when the transition succeeded — an illegal unpublish (from `SignupClosed`, `Running`, …) throws `IllegalCompetitionStateTransitionException` inside the transaction and must announce nothing. Put the `afterCommit` registration after the `transition()` call, not around it.

**Tests (extend `CompetitionLifecycleTest.php`):**
- `it('announces an unpublish once, with the event and brand it left')` — publish, unpublish; `CompetitionUnpublished` dispatched once with the four fields; `unpublishedAt` is an ISO-8601 string.
- `it('announces nothing when an unpublish is refused')` — a `SignupClosed` competition; the exception is thrown and `CompetitionUnpublished` is not dispatched.
- `it('carries the competition slug on CompetitionPublished')`.
- The existing `it('unpublishes back to draft and reopens signup')` stays green untouched.

---

### Task 2: The backfill — `CompetitionAnnounced` and `competition:announce-competitions`

**Requirements:** #331 "Backfill fuer bereits veroeffentlichte Competitions"; the 7c announce pattern (narrow, non-auditable, only a projection may listen) as `SocialIdentityAnnounced`'s docblock states it. A replay of `CompetitionPublished` is **not** acceptable: it is a lifecycle announcement whose future consumers (Messaging, metrics) would re-notify and re-count.

**Files:** `app-modules/competition/src/Events/CompetitionAnnounced.php`; `src/Console/AnnounceCompetitionsCommand.php`; `src/Providers/CompetitionServiceProvider.php` (add to the existing `$this->commands([...])` in `boot()`); `app-modules/competition/SPEC.md` (contract table row, marked "projection-only, backfill"); `tests/AnnounceCompetitionsCommandTest.php`.

**Interfaces produced:**
- `final readonly class CompetitionAnnounced { string $competitionId, string $eventId, string $brandId, string $name, string $slug, string $status, ?string $startsAt, bool $announced }`. `announced` is Competition's own answer to "is this publicly announced right now and not taken back": `status !== Draft && published_at !== null`. A draft says `false`, so a consumer that projected it earlier **unlearns** it (the `RegistrationStandingAnnounced::$confirmed` idea); a competition cancelled straight from draft (`published_at` null) says `false` too, because nobody ever saw it.
- `competition:announce-competitions {--chunk=500}` — `Competition::withoutBrandScope()->orderBy('id')->chunkById(...)`, one `CompetitionAnnounced` per row, every brand, every status; prints `Announced N competition(s).`. No module gate (the identity precedent: only projections listen, and Agenda's is ungated — Task 3). Safe to re-run.

**The boundary that bites:** the command runs on the CLI where `BrandScope` resolves no brand — without `withoutBrandScope()` it announces nothing and reports success.

**Tests:**
- `it('announces every competition of every brand with its current name, slug, status and start')` — two brands, three competitions; `Event::fake([CompetitionAnnounced::class, CompetitionPublished::class])`; three dispatched, fields match.
- `it('says announced false for a draft and for a competition cancelled before it was ever published, true for one cancelled after')` — the three `announced` cases, derived in a comment from `status`/`published_at`.
- `it('never replays CompetitionPublished')` — `Event::assertNotDispatched(CompetitionPublished::class)`.
- `it('walks the table in chunks')` — three rows, `--chunk=2`, three dispatched.

---

### Task 3: Agenda's read model and the selection table

**Requirements:** timetable design **§4.5** (both tables), **D-AGD-02**, **D-AGD-03**, §3 (Agenda reads no other module's table), competition design §14 (the projection removes its row on `CompetitionUnpublished`); issue acceptance "Verschieben/Absagen/Zurueckziehen aktualisiert das Programm".

**Files:** migrations `2026_09_24_100000_create_agenda_competitions_table.php`, `2026_09_24_100100_create_agenda_competition_entries_table.php`; `src/Models/ProgrammeCompetition.php` (table `agenda_competitions`, **no factory** — rows exist only through the projection, the `EventSummary` precedent); `src/Models/CompetitionEntry.php` (table `agenda_competition_entries`) + `database/factories/CompetitionEntryFactory.php`; `src/Enums/CompetitionBlockState.php`; `src/Listeners/ProjectProgrammeCompetition.php`; `AgendaServiceProvider::registerListeners()`; `tests/Helpers.php` (a helper `agendaPublishedCompetition(Brand $brand, string $eventId, array $overrides = []): string` that **emits `CompetitionPublished`** and returns the competition ULID); `tests/ProgrammeCompetitionProjectionTest.php`.

**Schema:**
- `agenda_competitions`: `id` ulid pk; `brand_id` foreignUlid → brands, cascade; `event_id` ulid; `competition_id` ulid **unique**; `name` string; `slug` string nullable; `starts_at` timestampTz nullable; `state` string(16); timestamps; index `(brand_id, event_id)`.
- `agenda_competition_entries`: `id` ulid pk; `brand_id` foreignUlid → brands, cascade; `event_id` ulid; `competition_id` ulid (**no FK** — the read-model row it pairs with is deleted on unpublish while the selection survives, see below); `track_id` foreignUlid nullable → `agenda_tracks`, `nullOnDelete`; `position` unsignedSmallInteger default 0; timestamps; unique `(event_id, competition_id)`.

**Interfaces produced:**
- `CompetitionBlockState::{Published, Cancelled}` (`published|cancelled`). Deliberately not Competition's `CompetitionStatus`: Agenda cares whether the block stands or is struck, nothing else.
- `ProgrammeCompetition` (`BelongsToBrand`, `HasUlids`; `$fillable = ['event_id', 'competition_id', 'name', 'slug', 'starts_at', 'state']`; casts `starts_at` → `immutable_datetime`, `state` → `CompetitionBlockState`).
- `CompetitionEntry` (`BelongsToBrand`, `HasUlids`, `HasFactory`; `$fillable = ['event_id', 'competition_id', 'track_id', 'position']`; `track(): BelongsTo<Track>`).
- `ProjectProgrammeCompetition` with `handlePublished(CompetitionPublished)`, `handleRescheduled(CompetitionRescheduled)`, `handleCancelled(CompetitionCancelled)`, `handleUnpublished(CompetitionUnpublished)`, `handleAnnounced(CompetitionAnnounced)`:
  - *published* → upsert keyed on `competition_id` (event, name, slug, `starts_at` = `CarbonImmutable::parse($startsAt)->utc()` or null, state `Published`);
  - *rescheduled* → update `starts_at` on an existing row only; an unknown competition is ignored (the event carries no name to create one from; the backfill is the repair);
  - *cancelled* → set state `Cancelled` on an existing row only — a competition cancelled from draft was never announced and has no block (the event's docblock warns consumers of exactly this);
  - *unpublished* → **delete the read-model row**; leave `agenda_competition_entries` alone;
  - *announced* → `announced === false` deletes the row; otherwise upsert as *published*, with state `Cancelled` when `status === 'cancelled'`.
  All five use `withoutBrandScope()->where('brand_id', $event->brandId)` and `forceFill`, the `ProjectEventSummary::project()` shape.

**Two decisions this task implements (recorded in Task 6's SPEC edit):**
1. **The listener is not short-circuited on the `agenda` gate.** Design §8 says "the Competition listeners short-circuit"; this plan instead follows the reasoning `ProjectEventSummary` and SPEC.md already give for the event projection — a brand that switches Agenda on after its tournaments were published must find them selectable, not wait for somebody to run a backfill. The read model holds a name and a time the competition's own public page already shows; nothing *acts* on it while the module is off. Task 6 amends §8 with a dated line.
2. **Unpublishing removes the block, not the organizer's choice.** The selection belongs to the programme editor (D-AGD-02); Competition may not delete it. A selected-then-unpublished competition disappears from the public programme at once (§14's requirement), the editor shows it as withdrawn with a remove button (Task 4), and republishing it brings the block back where the organizer put it.

**The boundary that bites:** a contract event is handled wherever it was fired — a console command, a queued job, a request on the operator admin host where `BrandScope` hardens to zero rows (ADR-0029). Every read and write in the listener escapes the scope and names the brand from the payload; a listener that relies on ambient brand works in the brand-host test and silently writes nothing from the console.

**Tests (`ProgrammeCompetitionProjectionTest.php`):**
- `it('projects a published competition with its name, slug and Competition-owned start')`.
- `it('moves the block when Competition reschedules it, and ignores a reschedule it never saw published')`.
- `it('strikes a cancelled competition and keeps the row')`; `it('ignores a cancel of a competition it never saw')`.
- `it('removes the row on CompetitionUnpublished and keeps the organizer\'s selection')` — entry row survives, read-model row gone.
- `it('brings the block back when the competition is published again')`.
- `it('backfills from CompetitionAnnounced and unlearns a draft')` — including `announced: false` deleting a previously projected row.
- `it('replays idempotently')` — each event delivered twice leaves one row with the same values.
- `it('projects while agenda is off for the brand')` — decision 1, pinned.
- `it('writes from the operator admin host, where the brand scope is empty')` — dispatch from inside an admin-host request context; the row exists afterwards.
- Model: `it('refuses the same competition twice in one event\'s selection')` (unique index), `it('leaves a selection untracked when its track is deleted')`.

---

### Task 4: Selecting competitions in the programme editor *(Vue-heavy)*

**Requirements:** design **§4.5** ("the programme editor selects"), **§6** (editor row: "and — half two — the competition selection"), **D-AGD-02**, §8 (one capability, `ManageAgenda`, covers the selection); issue acceptance "Veroeffentlichte Competition erscheint nach Auswahl".

**Files:** `src/Data/CompetitionEntryData.php`; `src/Data/CompetitionSelectionRowData.php`; `src/Actions/SaveCompetitionSelection.php`; `src/Programme/SelectedCompetitions.php`; `src/Http/Controllers/Admin/AgendaAdminController.php` (`edit()` gains a `competitions` prop; new `updateCompetitions()`); `routes/admin/agenda.php`; `resources/js/components/admin/CompetitionSelection.vue` (new); `resources/js/pages/admin/agenda/Edit.vue` (mounts it — **no new logic in the page**, it is already past the ~300-line limit); regenerated `resources/js/types/generated.d.ts` and Wayfinder (`wayfinder:generate --with-form`, manifest reset per `.ai/rules/js.md`); `tests/CompetitionSelectionTest.php`.

**Interfaces produced:**
- `CompetitionEntryData(string $competitionId, ?string $trackId, int $position)`, `#[TypeScript]`, `rules()`: `competitionId` required ulid `distinct`; `trackId` nullable ulid; `position` required integer min 0.
- `CompetitionSelectionRowData(string $competitionId, string $name, ?string $startsAt /* ISO */, bool $cancelled, bool $withdrawn, bool $selected, ?string $trackId, int $position)`, `#[TypeScript]` — the union of the event's read-model rows (`withdrawn = false`) and selection entries whose row is gone (`withdrawn = true`, `name` = "Withdrawn competition" since Agenda no longer knows it).
- `SelectedCompetitions::forEvent(string $eventId, string $brandId): list<array{entry: CompetitionEntry, competition: ProgrammeCompetition}>` — two queries (entries, then read-model rows `whereIn competition_id`), paired in PHP, entries without a row dropped. Consumed by Task 5's builder and `NowNext`.
- `SaveCompetitionSelection::handle(string $eventId, string $brandId, array $entries /* list<CompetitionEntryData> */): void` — whole-set replace in one `DB::transaction`, the `SaveTracks` shape: deletes entries not in the set, upserts the rest.
- Route `PUT /events/{event}/agenda/competitions` → `AgendaAdminController::updateCompetitions`, name `admin.events.agenda.competitions.update`; rules lifted from `CompetitionEntryData::rules()` onto `competitions.*.` exactly as `update()` lifts `TrackData`'s; flash `Competition selection saved.`; redirect to `admin.events.agenda.edit`.
- `CompetitionSelection.vue`: a list of the event's competitions with a checkbox, a track `<select>` (from the page's `tracks` prop) and up/down ordering buttons (no drag library — Edit.vue's own rule); its own `useForm` and the unsaved-changes guard (`.ai/rules/pages.md`, ADR-0038); a competition with `startsAt === null` is labelled "No start time yet — not shown in the programme"; a cancelled one "Cancelled — shown struck through"; a withdrawn one "Unpublished in Competition — not shown" with a remove action. Keyboard reachable (`.ai/rules/js.md`, BFSG). Console copy English.

**The boundary that bites:** the Action must refuse a `competitionId` that is not one of **this event's** read-model rows — else an editor could put another event's (or another brand's) tournament into this programme by posting its ULID — **but must accept a withdrawn one that is already an entry of this event**, because the editor posts the whole set back and an unpublished-but-selected competition is in it (Task 3, decision 2). Rule: allowed iff a `ProgrammeCompetition` row exists for `(brand, event, competition_id)` **or** a `CompetitionEntry` already does. A `trackId` must be a track of this event. Refusals are `ValidationException`s keyed `competitions.{index}.competitionId` / `.trackId`, so the component shows them on the row.

**Tests (`CompetitionSelectionTest.php`, admin host in the URL):**
- `it('offers the event\'s published competitions and none of another event\'s')` — `assertInertia` on `competitions`.
- `it('saves a selection of two with a track each, and replaces it whole on the next save')`.
- `it('refuses a competition of another event, and one of another brand')`.
- `it('refuses a track of another event')`.
- `it('keeps a withdrawn competition in the set when the editor saves it back, and removes it when told to')`.
- `it('refuses the selection without ManageAgenda')` (403) and `it('404s when agenda is off for the event\'s brand')`.
- `it('hides another operator\'s event from an editor holding ManageAgenda')` — the `summary()` operator check covers the new endpoint.

**Main checkout:** `vendor/bin/sail npm run build`, `lint`, `format`, `types:check` before commit; the page test needs the built manifest.

---

### Task 5: Competitions on the public programme and the hub card *(Vue-heavy)*

**Requirements:** design **§4.5** ("renders as an open-ended block at its start time"), **§5** (rows are distinct times; half-open spans; phone list; cancelled stays visible and struck — D-AGD-08 applies to a block exactly as to an item), **§6** (hub card), **D-AGD-03**, §10 item 7; issue acceptance boxes 1 and 2.

**Files:** `src/Programme/ProgrammeEntry.php` (new, internal); `src/Programme/ProgrammeBuilder.php`; `src/Programme/NowNext.php`; `src/Data/ProgrammeCellData.php`; `src/Enums/ProgrammeEntryKind.php`; `src/Http/Controllers/ProgrammeController.php` (passes the event slug); `resources/js/components/{ProgrammeGrid,ProgrammeList}.vue`, `resources/js/components/hub/NowNextCard.vue`; regenerated types; `tests/ProgrammeBuilderTest.php`, `tests/ProgrammePageTest.php`.

**Interfaces produced:**
- `ProgrammeEntryKind::{Item, Competition}` (`item|competition`).
- `ProgrammeCellData` gains `public readonly ProgrammeEntryKind $kind` and `public readonly ?string $url`, both at the end of the constructor with `ofItem()` passing `Item`/`null` — the existing tests' key assertions stay true. `itemId` keeps its name and holds the competition ULID for a block (documented on the property). New `ofCompetition(ProgrammeCompetition $competition, string $timezone, int $rowSpan = 1, ?string $url = null): self` — `startsAt` in the event's zone, `endsAt` null (D-AGD-03), `speaker`/`location` null, `cancelled` from `CompetitionBlockState::Cancelled`, `cancelledReason` null (Competition sends none). `of*`, not `from*` (`.ai/rules/data.md`).
- `ProgrammeEntry` — `final readonly class` with `id`, `?trackId`, `?CarbonImmutable $startsAt`, `?CarbonImmutable $endsAt`, and `cell(string $timezone, int $rowSpan): ProgrammeCellData`; named constructors `ofItem(AgendaItem)` and `ofCompetition(ProgrammeCompetition, CompetitionEntry, ?string $url)`. `ProgrammeBuilder`'s private `itemsOn()`, `rowTimes()`, `closingRowOf()`, `buildDay()` take `ProgrammeEntry` instead of `AgendaItem`; the arithmetic is unchanged.
- `ProgrammeBuilder::forEvent(string $eventId, string $brandId, array $days, string $timezone, ?string $eventSlug = null): array` — trailing optional parameter, so every existing call and test compiles unchanged. Adds `SelectedCompetitions::forEvent()`'s pairs whose `starts_at` is not null. The track is the **entry's** `track_id`; untracked goes to the band.
- The block's `url`: `route('competition.page', ['slug' => $eventSlug, 'competition' => $slug], absolute: false)` only when `$eventSlug` and the competition's `slug` are both non-null **and** `ModuleGate::isActiveForBrand('competition', $brandId)` — a link to a 404 on a card people follow in a hall is worse than none (`NowNextCard::programmeUrl()`'s reasoning).
- `NowNext::forEvent()` considers selected competitions with state `Published` and a start, under the same rules as items: open-ended, so "now" for `OPEN_ENDED_RUNS_FOR_MINUTES`, and the later start wins when several run. A cancelled block is never `now` or `next`.
- Vue: a block renders like an item, with a small "Turnier" label and its title as an Inertia `<Link>` when `url` is set; struck through with "Abgesagt" when cancelled. `ProgrammeList.vue` keys on `` `${cell.kind}:${cell.itemId}` ``. `NowNextCard.vue` shows the label and link — imports only `@/lib/*`, `@/components/ui/*` and `Konventix.Agenda.*` types.

**The boundary that bites:** refactoring the builder onto `ProgrammeEntry` must leave **every existing `ProgrammeBuilderTest` case passing unchanged** — the half-open span, the past-midnight span, the "Tag N counts omitted days" label, the id tie-break. Do not edit those tests; add new ones. Second: a competition whose `starts_at` is null has no anchor and **no row** — it is left out of the grid, not put in the untracked band (the band is still per day, and a block with no time has no day).

**Tests:**
- `ProgrammeBuilderTest`: `it('places a selected competition as an open-ended block in its entry\'s track')` (one row, `rowSpan` 1, `endsAt` null; wall clock derived in a comment from a UTC `starts_at` and `Europe/Berlin`); `it('adds a competition\'s start as a row time between two items')`; `it('leaves out a published competition nobody selected')`; `it('leaves out a selected competition with no start time')`; `it('keeps a cancelled competition on the page, struck, and never calls it now or next')`; `it('drops the block once Competition unpublishes it')`; `it('links a block to the competition page only while competition is on for the brand')`; `it('answers now and next across items and competitions')` — a 14:00 item and a 15:00 competition, asked at 15:30 → now = competition (began last), derivation in a comment.
- `ProgrammePageTest`: `it('shows a selected competition on the public programme, and moves it when Competition reschedules')` — the whole path through contract events: `agendaPublishedEvent()`, `agendaPublishedCompetition()`, a selection entry, GET; then emit `CompetitionRescheduled`, `forgetScopedInstances()`, GET again; `assertInertia` on both responses.

**Main checkout:** `npm run build`, `lint`, `format`, `types:check`.

---

### Task 6: SPEC, TODO, operations, design amendment

**Requirements:** CLAUDE_extra "update TODO.md in the same PR"; the plan-writing rule that a new backfill joins `docs/operations.md`'s main list; ADR-0059 (see the observability note below).

**Files:** `app-modules/agenda/SPEC.md` (*Owns* → half two built; *Consumes* table gets a real row for `CompetitionPublished` / `CompetitionRescheduled` / `CompetitionCancelled` / `CompetitionUnpublished` / `CompetitionAnnounced` stating "ungated, like `ProjectEventSummary`" and why; the module-intro "waits on" sentence goes); `app-modules/agenda/TODO.md` (tick "The competition half"; add the two follow-ups under *Not in this plan* as backlog lines); `docs/superpowers/specs/2026-09-23-timetable-design.md` §8 (one dated *Amended* line, in the style of the existing one: the Competition listeners are **not** short-circuited, and why); `docs/operations.md`; `tests/AgendaActivationTest.php`.

**`docs/operations.md`:**
- The main backfill list (the paragraph under "Backfilling Competition's linked accounts" beginning "It joins the manual post-rollout backfills, which are now …") gains `competition:announce-competitions`, with the cluster form `kubectl --context rancher -n konventix exec <web pod> -- php artisan competition:announce-competitions`.
- § *Programme* gains "### Tournaments in the programme": the editor's selection, that Competition owns the time, that an unpublish removes the block and a republish restores it, that a **rename or an event move of an announced competition is not propagated** (no contract event carries it) and `competition:announce-competitions` is the repair, and that the command is safe to re-run and must run once after the 0.9.1 deploy (until then every already-published tournament is missing from the editor's list).

**Observability:** no new metric, on purpose — this half adds no operational state of its own: it is a projection whose freshness is the backfill's, exactly like `agenda_event_summaries`, which has none either. `ObservabilityCoverageTest` is unaffected. Say so in the SPEC's *Observability* paragraph so the absence reads as a decision.

**Tests (`AgendaActivationTest.php`):**
- `it('has no competition-selection endpoint when agenda is off')` — 404 on the PUT.
- `it('shows no competition blocks when agenda is off, yet has them ready when it is switched on')` — the projection ran while off (Task 3's decision); after activation the public page shows the selected block.

---

## Ordering

1 → 2 → 3 → 4 → 5 → 6. Task 3 consumes the events Tasks 1 and 2 produce. Tasks 4 and 5 both need Task 3 and are independent of each other — 5 can run first, since its tests create selections through `CompetitionEntryFactory`, not the editor. Tasks 4 and 5 are the Vue-heavy ones; everything runs in the main checkout.

## Self-review against the spec

| Source | Task |
|---|---|
| Competition design §14 "Unpublishing a competition tells nobody" — `CompetitionUnpublished(competitionId, eventId, brandId, unpublishedAt)` from `UnpublishCompetition` | 1 (emit), 3 (projection removes its row) |
| Competition design §12 — additive contract, scalars | 1 (`slug`), 2 (`CompetitionAnnounced`) |
| #331 "Backfill fuer bereits veroeffentlichte Competitions" | 2 (command), 3 (`handleAnnounced`), 6 (operations list) |
| Timetable §4.5 `agenda_competitions` read model | 3 |
| Timetable §4.5 `agenda_competition_entries` + D-AGD-02 the editor selects | 3 (table), 4 (editor) |
| D-AGD-03 Competition owns the start; open-ended block | 3 (never written by Agenda), 5 (`endsAt` null, one row) |
| D-AGD-01 half two after #296 | the whole plan; 6 closes the TODO item |
| §5 rendering (rows, half-open spans, phone list, cancelled struck) | 5 |
| §6 surfaces (editor selection, public page, hub "Jetzt / Gleich") | 4, 5 |
| §8 one capability, activation, no GDPR, observability | 4 (capability), 6 (activation, amended listener gate, no metric) |
| §10 item 7 two selected competitions, rescheduling moves them | 4 + 5 (`ProgrammePageTest`) |
| #331 acceptance 1 (appears after selection) | 4, 5 |
| #331 acceptance 2 (reschedule / cancel / unpublish update the programme) | 3, 5 |
| #331 acceptance 3 (backfill) | 2, 3, 6 |

**Not in this plan:**
- **Renaming, re-slugging or moving an announced competition to another event** propagates nothing: `UpdateCompetition` emits `CompetitionRescheduled` for a time change only, and no contract event carries name, slug or event. The block keeps the old name (and a stale link after a re-slug) until `competition:announce-competitions` runs. Closing it needs a new Competition contract event (e.g. a details-updated event on announced competitions), which no design has settled — a follow-up issue, not an invention here.
- **A competition's end time** — #117's duration estimator (D-AGD-03); the block gains an end then, and nothing here changes.
- **Following `CompetitionStarted` / `CompetitionFinished`** for a truer "Jetzt": the design names three lifecycle events for the read model, and the open-ended two-hour rule applies to blocks as to items.
- **Individual matches** in the programme (D-AGD-09), personal agendas, and notifications on `CompetitionUnpublished` (Messaging, later).

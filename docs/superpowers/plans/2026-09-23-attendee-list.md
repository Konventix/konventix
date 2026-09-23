# Attendee List and Console Check-in Implementation Plan (0.9.1, #329)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A guest whose QR code does not work can be found by name at a console desk and checked in. The check-in writes the same record as a door scan and has the same effects. An organizer can see who is on site, one row per ticket, and open any ticket to see its history.

**Architecture:** Admission owns everything. `RecordScan` is split in two. Token verification stays in `RecordScan`, and a new core, `RecordCheckIn`, runs every admission-level check and writes the `CheckIn`. The door and a new `CheckInFromConsole` both go through that core, so every downstream consumer (presence, hub, shoutbox badge, competition door presence, Commerce's refund block, Registration's door verification) reacts the same way to both. The console writes against a per-event checkpoint with `kind = console` and records the acting admin on `check_ins.actor_user_id`. It also raises an auditable event. The list and the ticket page read only Admission's own tables. That includes one new read model, `admission_registration_summaries`, which gives the ticket type label and the registration's status. It is projected from Registration's events: two of them gain the type name, additively.

**Tech Stack:** Laravel 12 modules (`app-modules/admission`, small additive changes in `app-modules/registration`, one link in `app-modules/event`), Pest on Postgres, spatie/laravel-data + typescript-transformer, Inertia v3 + Vue 3 + shadcn-vue, Wayfinder.

**Spec:** `docs/superpowers/specs/2026-09-23-attendee-list-design.md` (D-ATT-01…08). This is the binding authority. Every task names the sections it implements, and those sections are its requirements. Issue: #329 (closes with Task 5).

## Global Constraints

- Work happens in the MAIN checkout `/home/mawiguko/git/konventix/konventix-poc` on `develop` (no worktree).
- **No local test runs** (operator instruction). Write the tests, and the GitLab pipeline runs them. pint and phpstan run locally before every commit, and for frontend so do `npm run lint`, `format`, `types:check` and `build`, all through `vendor/bin/sail`. Regenerate Wayfinder with `--with-form` (or through `npm run build`). Before `typescript:transform`, reset the types manifest (`.ai/rules/js.md`).
- Tests derive expected values by hand and show the derivation in a comment.
- The format-on-edit hook strips unused imports, so add a `use`/`import` in the same edit as its first use.
- ULIDs (ADR-0014). `brand_id` is never mass-assigned. Events are `final readonly` and carry ULIDs and scalars only (ADR-0004). **New** events raised inside a transaction go out through `DB::afterCommit()` (`.ai/rules/src.md`). The one deliberate exception is that `AdmissionCheckedIn`/`CheckInRejected` keep their current synchronous `event()` inside the transaction, because moving them would change door behaviour, which Task 1 forbids. Datetime parameters are `?CarbonInterface` (#312).
- Console copy is in **English** (ADR-0001). Listeners are registered explicitly in `AdmissionServiceProvider::registerListeners()` (discovery is off). Admission is Core (`$gateable = false`), so its listeners have no Pennant short-circuit.
- Console routes are operator-scoped and capability-gated. The capability check comes first (a 403 without it), then the operator check (a 404 for another operator's row). The pattern is `ScopesToOperator` in app-modules/competition, **copied** into Admission, because a module never imports another module's non-event class. Every admin-host query escapes `BrandScope` on every level, relations included (`.ai/rules/app-modules-src.md`).
- Console tests put the admin host in the URL (`http://{$operator->admin_host}/admin/…`), create brand-owned rows **before** the first admin-host request, and call `$this->app->forgetScopedInstances()` between two requests in one test (`.ai/rules/tests.md`). A helper that a second file calls goes in `app-modules/admission/tests/Helpers.php`, prefixed `attendee…`, and is only ever appended.
- Read these before touching matching paths: `.ai/rules/{app-modules-src,src,models,data,listeners,providers,tests,tickets,js,pages,resources,observability}.md`.
- Commit messages are one plain sentence in the repo's style, ending with:
  `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`
  `Claude-Session: https://claude.ai/code/session_01QPCmPxfGD9UDAAUGKgwEbN`
- New post-rollout backfills go in `docs/operations.md`'s main backfill list. This plan adds no new command, but it widens what `registration:announce-standing` fills (Task 2).

## What already exists (verified 2026-09-23 — do not rebuild)

- **The door path:** `EntranceController::scans()` → `SynchronizeScanBatch::handle()` → `RecordScan::handle(token, brandId, eventId, clientScanId, scannedAt, checkpoint, device, direction, conditionsChecked)`. `RecordScan` runs its checks in this order: idempotency → signature → expiry → wrong event → lock admission / unknown → `VerifyAdmissionToken::isSuperseded()` → outside every event day (`EntranceEventProfile::dayCovering()`) → token day scope (`AdmissionTokenPayload::admitsOnDay()`) → `EvaluateAdmissionValidity` (state `issued|assigned`, the validity window) → re-entry (`ReentryPolicy::admitsAgain()` on `in` only) → accept. An accept writes the `CheckIn`, adds an `EntranceDeltaEntry::KIND_CHECKED_IN` on `in`, and emits `AdmissionCheckedIn` (carrying `conditionsChecked`). A reject writes a rejected `CheckIn` with `reason_code` and emits `CheckInRejected`. `database/seeders/Demo/DemoAdmissionSeeder.php` also calls `RecordScan::handle()`.
- **Consumers of `AdmissionCheckedIn`:** Admission's `ProjectPresenceOnAdmissionCheckedIn` (queued, `afterCommit`) → `ProjectEventPresence::checkIn()` → `ParticipantAdmitted` / `ParticipantPresenceChanged` (the hub, Community's badge). Commerce's `ProjectCheckInOnAdmissionCheckedIn` (ADR-0037 refund block). Registration's `RecordDoorVerification` (door-check outcomes → `DecideEntryConditionAssertion`). Competition's `ProjectAdmissionPresence`.
- **Models:** `Admission` (`holder_user_id`, `buyer_user_id`, `registration_id` nullable for crew, `entry_conditions` `[{key,label,verification,document_hint}]`, `state`, validity). `CheckIn` (no actor, and no record of condition outcomes). `Checkpoint` (`event_id`, `name`, no kind). `Person` (`admission_people`: `display_name`, `username`, `email`, not brand-scoped). `EventSummary` (`admission_event_summaries`: `brand_id`, `event_id`, `name`). `EntranceEventProfile`. `EventPresence`.
- **The ticket reference already exists** as the "door code": `strtoupper(substr($id, -8))` grouped `XXXX-XXXX`. It is duplicated in `TicketDocumentRenderer::doorCode()` and `TicketPageController::doorCode()`, printed on the PDF (`door-code`) and on the holder's page (`TicketHolderBand.vue`). §3's "add it there if absent" is therefore already satisfied. Task 2 only folds the two copies into one class.
- **Capabilities / roles:** `Permission` has 10 cases, among them `ScanCheckIn` and `PrintBadge`. `defaultRoles()` ships `admission.entrance-staff`. `DefaultRole::of()` refuses another module's capability. Event ships **no** default role. Platform's Brand Admin (`everything`) and the operator's `Operator Admin` (re-granted the full catalogue on every `permissions:sync`) pick up any new case by themselves.
- **Console:** `routes/admin/admission.php` (`admin.events.admission-stats`, `admin.admissions.badge`). Neither controller is operator-scoped today. Admin pages use `@/components/admin/{DataTable,DataTableToolbar,AdminPageHeader}.vue`, `useDataTable`, `HasDataTableQuery` and `@/components/ui/{dialog,dropdown-menu}`. The user page is `admin.users.edit` (`/admin/users/{user}/edit`) and the registration page is `admin.registrations.show` (`/admin/registrations/{registration}`). The event dashboard is `app-modules/event/resources/js/pages/admin/events/Dashboard.vue`, which already has Programme and Shoutbox buttons.
- **Registration events:** no event carries a registration type name. `RegistrationConfirmed` carries `registrationTypeId`. `RegistrationStandingAnnounced` is the projection-only backfill event emitted by `registration:announce-standing`, which is already in the main backfill list. `RegistrationCancelled` carries no `brandId`.
- **Audit:** `Konventix\Compliance\Audit\AuditableEvent` (`auditEntry(): AuditEntry(action, subjectId, brandId, actorUserId, context)`) is a sanctioned cross-module contract. Compliance's wildcard subscriber records it.

---

### Task 1: Split `RecordScan` into token verification and a shared core (riskiest task)

**Requirements:** spec **§3.1** (the `RecordScan` / `RecordCheckIn` half, not `CheckInFromConsole`), D-ATT-08, and **§7**'s "every existing door-scan test stays green unchanged".

**Files:** `src/Actions/RecordCheckIn.php` (new). `src/Actions/RecordScan.php` (shrinks to token work plus delegation). One migration adding `check_ins.actor_user_id` (nullable `ulid`, no FK: users belong to Identity, ADR-0014, indexed) and `check_ins.conditions_checked` (nullable `jsonb`). `src/Models/CheckIn.php` (fillable, `conditions_checked` cast `array`, `@property`). `tests/RecordCheckInTest.php` (new).

**Interfaces produced:**
- `RecordCheckIn::handle(Admission $admission, string $eventId, string $clientScanId, CarbonInterface $at, ?Checkpoint $checkpoint = null, ?EntranceDevice $device = null, string $direction = 'in', array $conditionsChecked = [], ?string $actorUserId = null, array $admittedDayIds = []): CheckIn`. The brand is `$admission->brand_id`. `$admittedDayIds` is the token's day scope, and `[]` means every day. The console passes nothing, because an admission stores no day restriction (tokens are minted without one). The method runs, **in today's order**: idempotency on `client_scan_id` → outside every event day → not valid on this day (same semantics as `AdmissionTokenPayload::admitsOnDay()`) → `EvaluateAdmissionValidity` → re-entry on `in` → accept. The caller holds the admission's row lock. `handle()` opens its own `DB::transaction` (a savepoint when nested).
- `RecordCheckIn::reject(string $brandId, string $eventId, ?string $admissionId, ?string $dayId, string $clientScanId, CarbonInterface $at, ?Checkpoint $checkpoint, ?EntranceDevice $device, string $direction, CheckInRejectionReason $reason, ?string $actorUserId = null): CheckIn`. This is the single writer of rejected rows. `RecordScan` uses it for its five token-level reasons (`InvalidSignature`, `TokenExpired`, `WrongEvent`, `UnknownAdmission`, `NonceSuperseded`).
- The private `record()` moves into `RecordCheckIn`. It stamps `actor_user_id` on both outcomes. It stamps `conditions_checked` on accepted rows only (`null` when the list is empty), and rejected rows keep it null, as the event does today. Everything else is byte-for-byte what `accept()`/`reject()` write today: the delta entry, `AdmissionCheckedIn`'s ten arguments, `CheckInRejected`'s seven arguments, and the synchronous `event()` inside the transaction.
- `RecordScan::handle()` keeps its **exact signature**, the idempotency check, verification, the lock and `isSuperseded()`. After those it calls `$this->recordCheckIn->handle(..., admittedDayIds: $payload->dayIds)`. The constructor gains `RecordCheckIn` and loses `EvaluateAdmissionValidity`. `SynchronizeScanBatch`, `EntranceController` and `DemoAdmissionSeeder` are not touched.

**The boundary that bites:** the split must not change door behaviour. That means the same reason codes in the same precedence, the same rows, the same events in the same order and inside the same transaction, and the same idempotency. **These existing test files must stay green without a single edit.** The reviewer checks `git diff develop --stat -- <paths>` is empty for all of them, and the pipeline runs them:
- `app-modules/admission/tests/EntranceApiTest.php`
- `app-modules/admission/tests/EntranceDayAndReentryTest.php`
- `app-modules/admission/tests/EntryConditionsAtTheDoorTest.php`
- `app-modules/admission/tests/AdmissionTokenStabilityTest.php`
- `app-modules/admission/tests/EventPresenceProjectionTest.php`
- `app-modules/registration/tests/EntryConditionVerificationTest.php` (door-verification cases)
- `app-modules/commerce/tests/RefundAndCreditNoteTest.php` (the check-in refund block)
- `app-modules/competition/tests/CheckInTest.php` (door presence "the way Admission really emits it")
- `tests/Feature/SeedDemoDataCommandTest.php` ("seeds checkpoints, entrance devices and real check-ins", through `DemoAdmissionSeeder` → `RecordScan`)

If any of them needs a change to pass, the refactor is wrong. Do not change the test.

**Tests (`RecordCheckInTest`, new file only):**
- An admission handed straight to the core with no token is accepted, and the row carries `actor_user_id` and `conditions_checked`.
- A replayed `client_scan_id` returns the first row and emits nothing twice.
- `$admittedDayIds = ['d1']` on day `d2` is refused `not_valid_on_this_day`, while `[]` on `d2` is accepted. This is the `admitsOnDay()` parity case.
- A calendar with no day for the instant is refused `outside_event_day` before validity is asked. Build the admission as revoked too, so the reason proves the order: a revoked admission outside every day must read `outside_event_day`, not `admission_not_valid`.
- `reject()` writes a row with `registration_id` null and emits `CheckInRejected` with the reason's value.
- A door scan's row has `actor_user_id` null and, when a door check was answered, `conditions_checked` equal to the uploaded list. That list is `[{key: student, outcome: refused}]`, taken from the same payload shape `EntryConditionsAtTheDoorTest` uses and built with a new helper, not by editing that file.

---

### Task 2: What the list needs to know — ticket type, registration status, one ticket reference

**Requirements:** spec **§3** (both bullets: "A ticket type label" and "A short ticket reference"), **§4.4**'s "the registration's details as Admission knows them (type, status, registration id)", ADR-0004.

**Files:**
- Registration: `src/Events/RegistrationConfirmed.php` gains a trailing `public readonly ?string $registrationTypeName = null`. This is the narrowest event that issues admissions, so it is chosen over `RegistrationAmended`, which cannot change the type. `src/Actions/ConfirmRegistration.php` passes `$registrationType->name`. `src/Events/RegistrationStandingAnnounced.php` gains trailing `?string $registrationTypeId = null`, `?string $registrationTypeName = null` and `?string $status = null`, all additive. `src/Console/AnnounceRegistrationStandingCommand.php` fills them. Its chunk loads each type with `->with(['registrationType' => fn ($q) => $q->withoutGlobalScope(BrandScope::class)])`, because the command runs with no brand and BrandScope would read null otherwise. Update the SPEC contract rows for both events. Test: `tests/AnnounceRegistrationStandingCommandTest.php` gains one case, appended.
- Admission: a migration for `admission_registration_summaries` (`id` ULID, `brand_id` FK `brands`, `registration_id` ulid **unique**, `registration_type_id` ulid nullable, `type_name` string nullable, `status` string(16), timestamps). `src/Models/RegistrationSummary.php` (`BelongsToBrand`, `HasUlids`, no factory; tests emit the events, as with `EventSummary`). `src/Listeners/ProjectRegistrationSummary.php` with `handleConfirmed`, `handleCancelled` and `handleStandingAnnounced`, registered in the provider. `src/Support/TicketReference.php`. `TicketDocumentRenderer` and `TicketPageController` lose their private `doorCode()` and call `TicketReference::of()` (`.ai/rules/tickets.md` applies to both files). Tests: `tests/RegistrationSummaryProjectionTest.php` and `tests/TicketReferenceTest.php`.

**Interfaces produced:**
- `TicketReference::of(string $admissionId): string`, with the output `XXXX-XXXX`. `TicketReference::searchFragment(string $typed): string` strips hyphens and whitespace. It exists so a typed `ab12-cd34` and `AB12CD34` both match `admissions.id ILIKE %…%`: ULIDs are stored lower-case, and `ILIKE` ignores case.
- `RegistrationSummary`, written only by the projection. `status` is `confirmed` on `RegistrationConfirmed` and `cancelled` on `RegistrationCancelled`. `RegistrationCancelled` carries no brand, so it updates an existing row and never creates one. From `RegistrationStandingAnnounced`, the projection upserts the type id and name and takes `status` from the event (or `confirmed`/`unconfirmed` from `$confirmed` when `status` is null). A `null` type name in the payload never overwrites a stored one.

**The boundary that bites:** Admission imports only `Konventix\Registration\Events\*` (`RegistrationCommerceImportBoundaryTest`). Only a projection may listen to `RegistrationStandingAnnounced`, so the listener's name starts with `Project`. Every existing `new RegistrationConfirmed(...)` in any module's tests keeps compiling because the new argument is trailing and defaulted. Competition's `ProjectEligibilityOnRegistrationEvents` must not need a change.

**Tests:**
- A confirmation projects type id, name and `confirmed`. A cancellation flips the status. Cancelling an unknown registration writes nothing. Replaying the confirmation twice leaves one row.
- The standing announcement backfills a registration confirmed before this deploy, and a standing without a type name keeps the stored name.
- `registration:announce-standing` emits the type name even though the command runs with no brand in context.
- `TicketReference::of('01jabcdefghjkmnpqrstvwxyz9')` is `'STVW-XYZ9'`. The comment derives it: characters 19–26 of the 26 are `stvwxyz9`, which upper-cased and split 4+4 give `STVW-XYZ9`. `searchFragment(' stvw-xyz9 ')` is `'stvwxyz9'`. The page and the PDF still print the same string for one admission. `TicketPageTest` and `TicketDocumentTest` stay unchanged.

**Docs:** `docs/operations.md`: the `registration:announce-standing` bullet in the main backfill list also names `admission_registration_summaries` (the attendee list's ticket type and registration status). Until it runs, pre-0.9.1 tickets show no type in the list.

---

### Task 3: The console check-in core — `CheckInFromConsole`, the console checkpoint, door checks, audit

**Requirements:** spec **§3.1** (`CheckInFromConsole`, `actor_user_id`, audit), **§3.2** (the ADR-0033 amendment), **§4.3**'s rules (door checks, refusals as sentences, disabled states), **§6** (actor GDPR, the console checkpoint), D-ATT-04, D-ATT-08, ADR-0059.

**Files:**
- A migration adding `checkpoints.kind` (string(16), default `door`) plus a partial unique index on `(brand_id, event_id) WHERE kind = 'console'` (a raw `DB::statement`, Postgres).
- `src/Enums/CheckpointKind.php`. `Checkpoint`: `kind` cast, `scopeDoors()`.
- `src/Actions/CheckInFromConsole.php`, `src/Support/OwedDoorChecks.php` and `src/Exceptions/ConsoleCheckInRefusedException.php`.
- `src/Events/ConsoleCheckInRecorded.php` (implements `AuditableEvent`).
- `CheckInRejectionReason::consoleSentence(): string` (English, one per case).
- `AdmissionCheckedIn::$registrationId` widened to `?string` (see below). Commerce's `ProjectCheckInOnAdmissionCheckedIn` returns early on null.
- `src/Metrics/AdmissionMetrics.php` gains `admission_console_check_ins_total{brand, outcome}`, recorded by `src/Listeners/Metrics/RecordConsoleCheckInMetrics.php`. Add a stat and a per-hour timeseries to `contrib/observability/src/sale.mjs`'s admission row, then `npm run observability:build`.
- `AdmissionGdprHandler`.
- `docs/decisions/0033-entrance-offline-trust-model.md`.
- Tests: `tests/ConsoleCheckInTest.php`, `tests/ConsoleCheckpointTest.php`, and `tests/AdmissionGdprHandlerTest.php` (append).

**Interfaces produced:**
- `CheckpointKind::{Door, Console}`. `Checkpoint::scopeDoors()` is what #328's enrolment surface must use. Nothing enrols devices today, so nothing else changes.
- `CheckInFromConsole::handle(Admission $admission, string $actorUserId, string $direction, array $conditionsChecked = [], ?CarbonInterface $at = null): CheckIn`. In one transaction it:
  1. locks the admission (`lockForUpdate`, `withoutBrandScope()`, the admission's own brand);
  2. **inside the lock** refuses `in` when the last accepted check-in is `in`, and refuses `out` when it is not. These refusals throw `ConsoleCheckInRefusedException` with a sentence and write **no** row: the door has no such rule, so there is nothing door-equivalent to record;
  3. resolves the event's console checkpoint with `Checkpoint::withoutBrandScope()->createOrFirst(...)` against the partial index (`name = 'Console'`, `kind = console`, the admission's brand stamped via `forceFill`);
  4. calls `RecordCheckIn::handle()` with `clientScanId = 'console-'.Str::ulid()`, `device = null`, and the actor;
  5. hands `ConsoleCheckInRecorded` to `DB::afterCommit()`.

  Every door rule (validity, revoked, day, re-entry) is the core's. A console refusal from the core **is** a rejected `CheckIn` plus `CheckInRejected`, exactly as at the door. The caller turns `reason_code` into `consoleSentence()`.
- `OwedDoorChecks::for(Admission $admission, ?string $dayId): list<array{key: string, label: string, documentHint: string|null}>`. The owed checks are the `document_at_door` entries of `entry_conditions` with no `confirmed|refused` outcome in `conditions_checked` on an accepted check-in of this admission on `$dayId` (any day when the event has no calendar). There is also a batched `forMany(Collection $admissions, ?string $dayId): array<string, list<…>>` for the list page. `$dayId` comes from `EntranceEventProfile::dayCovering(now())`.
- `CheckInFromConsole` validates `$conditionsChecked` against the owed keys: every owed key must be answered, and no unknown key is accepted. Outcomes are `confirmed|refused` (the door's `Rule::in`). A refusal still checks in. That is ADR-0066 §5 and what `EntryConditionsAtTheDoorTest` pins for the door ("accepted: 1" with a refused outcome), so the console does the same and the dialog says so (Task 5).
- `ConsoleCheckInRecorded(checkInId, admissionId, eventId, brandId, actorUserId, direction, result, reasonCode)`, all scalars. `auditEntry()` returns the action `admission.console-check-in` or `admission.console-check-out`, the subject being the admission, the brand, the actor, and the context `{result, reason_code, check_in_id}`.
- The metric's `outcome` takes one of the values `in|out|refused`, a bounded set (`.ai/rules/observability.md`).

**The one fix the console surfaces:** a crew admission (`IssueCrewAdmission`, D-STF-01) has `registration_id = null`, and `AdmissionCheckedIn::$registrationId` is a non-null `string`. Under `strict_types`, an accepted scan of a crew badge therefore throws a `TypeError` today, at the door as well as from the console. Widen the property to `?string`, and make Commerce's projection skip a null. Registration's `RecordDoorVerification` already finds no row for a null id. Competition's `ProjectAdmissionPresence` does not read the field. Update Admission's SPEC contract row. The door tests listed in Task 1 are not edited. A **new** door-path test for the crew case goes in `ConsoleCheckInTest` (or a new file).

**GDPR:** export `check_ins` rows where `actor_user_id = subject` as `console_check_ins` (`id`, `admission_id`, `direction`, `result`, `scanned_at`). On delete, in both modes, set `actor_user_id` to null for the subject. The rows stay, because they are the guest's attendance record, not the admin's data.

**ADR-0033:** add a blockquote under the status line in ADR-0012's convention, and set the status line to `accepted, amended`: "> **Amended 2026-09-23 by the attendee-list design** (`docs/superpowers/specs/2026-09-23-attendee-list-design.md`, #329)." The blockquote says three things. A second, online path exists: the operator console, which is capability-gated and name-aware. It writes through the same core, `RecordCheckIn`, so both paths share one set of admission checks. And it never weakens the door's model: the delta feed, the devices and the token stay PII-free, and the console checkpoint is never offered to a device.

**Tests:**
- A console check-in writes an accepted `CheckIn` with `actor_user_id`, the console checkpoint and `device_id` null. It emits `AdmissionCheckedIn` with the checkpoint's id. For an assigned holder, it creates the `EventPresence` row with `hub_access = true` and emits `ParticipantAdmitted` once. These are the assertions `EventPresenceProjectionTest` makes for a scan.
- A check-out after it flips `last_direction` to `out` and emits `ParticipantPresenceChanged('out')`.
- Owed door checks: `confirmed` gives an accepted row with `conditions_checked` stored and carried on the event. `refused` gives an accepted row with the refusal stored, and the admission is still `issued` (T-8). An unanswered owed check, or an unknown key, is refused before anything is written. Once answered on this day, a second check-in owes nothing.
- A revoked ticket gives a rejected row `admission_not_valid` plus `CheckInRejected`, and `consoleSentence()` returns a non-empty English sentence.
- `once_only` re-entry after check-out gives a rejected row `reentry_not_permitted`, the same as `EntranceDayAndReentryTest` shows at the door.
- A second `in` while in, or an `out` while never in, throws `ConsoleCheckInRefusedException`, and the `check_ins` count is unchanged (derive: 1 before, 1 after).
- The console checkpoint is created once per event and reused. Two events get two. `doors()` excludes it.
- `ConsoleCheckInRecorded` is dispatched after commit with the actor, and no audit event is written for a refused-before-write attempt.
- A crew admission (no registration) is accepted both from the console and through `/api/entrance/scans`, with no error.
- The metric increments `in`, `out` and `refused` once each for the three cases above.
- GDPR export lists the admin's console check-ins, and erasure nulls `actor_user_id` while the row stays.

---

### Task 4: The attendee list — capability, role, scoping, search, filters, page (Vue-heavy)

**Requirements:** spec **§4.1** (the tab, columns, pagination, default sort), **§4.2** (search), **§5** (capability, the Einlass role, capability then operator scoping), D-ATT-01, 02, 05, 06, 07, and **§7**'s search, access and link cases.

**Files:**
- `src/Enums/Permission.php` gains `CheckInAttendees = 'admission.check-in-attendees'`. `defaultRoles()` gains `DefaultRole::of('admission.einlass', 'Einlass', Permission::CheckInAttendees)`.
  *Revised 2026-09-23: no Einlass role ships in 0.9.1 — D-ATT-06's role part is deferred to #334 (operator-scope console roles). Only the capability ships.*
- `src/Http/Controllers/Admin/Concerns/ScopesToOperator.php`, a copy of Competition's without the Pennant filter. Its methods are `findEventSummary(string $event): EventSummary` and `ensureOperatorOwns(string $brandId): void`.
- `src/Http/Controllers/Admin/AttendeeListController.php` (uses `HasDataTableQuery`) and `src/Queries/AttendeeListQuery.php`.
- `src/Data/{AttendeeRowData,AttendeeCheckInStateData,DoorCheckData}.php` (`#[TypeScript]`).
- `routes/admin/admission.php`: `GET /events/{event}/attendees` → `admin.events.attendees.index`.
- `resources/js/pages/admin/attendees/Index.vue`, with the row cells in `resources/js/components/attendees/`.
- The event module's `Dashboard.vue` gets an "Attendees" button beside Programme and Shoutbox (`index as attendeesIndex` from `@/routes/admin/events/attendees`). There is no `modules` flag, because Admission is Core.
- `tests/AttendeeListTest.php` and `tests/Helpers.php` (append `attendeeConsoleOperator()`, `attendeeFixture()`).

**Interfaces produced:**
- `AttendeeRowData`: `admissionId`, `reference` (`TicketReference::of`), `state`, `holderUserId`, `holderName`, `holderUsername`, `buyerUserId`, `buyerName`, `email` (the holder's, else the buyer's), `ticketType` (`RegistrationSummary.type_name`, else the admission `role`), `registrationId`, `registrationShortId`, `checkIn` (`AttendeeCheckInStateData`: `direction` `in|out|null`, `at`, `source` `door|console`, derived from the checkpoint's `kind`), and `owedDoorChecks` (`list<DoorCheckData>`, filled only for rows that can check in). No entry-condition *assertion* reaches this list (ADR-0066, Consequences). The list shows labels only inside the check-in dialog, which ADR-0066 §5 allows on the console.
- `AttendeeListQuery::handle(EventSummary $event, ?string $search, ?string $filter, array $sort, int $perPage): LengthAwarePaginator` (the class is called `AttendeeListQuery`). It works on `admissions` (`withoutBrandScope()`, `brand_id` and `event_id` from the summary) with two left joins onto `admission_people` (holder and buyer), a left join onto `admission_registration_summaries`, and the latest accepted check-in per admission from a `DISTINCT ON (admission_id)` subquery joined to `checkpoints`. One page is a fixed number of queries whatever its size (`shouldBeStrict()`).
- Search (§4.2) is one `ILIKE` term, with `%`, `_` and `\` escaped, OR-ed across the holder's and buyer's `display_name`, `username` and `email`, `admissions.id`, `admissions.registration_id` and `type_name`, plus `admissions.id ILIKE %TicketReference::searchFragment(term)%`. There is no trigram index: the list is per event (spec §4.2).
- Filters are declared once through `resolveFilters(['presence' => ['in', 'not_in'], 'view' => ['unassigned', 'revoked']])` and once as `DataTableFilterDef`s (`.ai/rules/pages.md`). `not_in` is the exact complement of `in` (never checked in, or last accepted direction `out`), so the two partition the list. Sorts: `activity` (default, last accepted `scanned_at`, **desc** when no `sort` is given, nulls last) and `name` (`coalesce(holder.display_name, buyer.display_name)`).
- Page props are `event {id, name}`, `attendees` (paginated `AttendeeRowData`), `filters`, and `canPrintBadge`.

**The one boundary that bites:** the admin host resolves permissions against the **operator's** team (ADR-0029), so the capability that opens this page is the one on the operator-scoped role. `Operator Admin` gets `CheckInAttendees` on the next `permissions:sync`. A brand-scoped `Einlass` role is materialized as the spec asks, but a holder of it reaches no console page until ADR-0029's per-brand admin exists. This is the same state Competition's `Tournament Desk` is in today. Record it in Admission's TODO.md and do not work around it. The Vue page: each row's user name is a real `<Link>` to `admin.users.edit` and the reference is a `<Link>` to `admin.tickets.show` (keyboard reachability, `.ai/rules/js.md`). Sortable headers are buttons with `aria-sort`. Timestamps go through `formatDateTime()`.

**Tests (`AttendeeListTest`):**
- **Search:** one case per field, 13 in all: holder name, username and email; buyer name, username and email; the reference typed `XXXX-XXXX`, the same reference without the hyphen, and in lower case; the full admission id; the registration id; the type label; and a non-match returning 0 rows. Each seeds three admissions and derives the single expected hit in a comment.
- **Filters:** `in`/`not_in` partition a fixture of 4 (the derivation in the comment: one in, one out, two never, so 1 and 3). `unassigned` returns only rows with no holder. `revoked` returns only revoked rows.
- An unassigned ticket shows "no holder" plus the buyer's name. A person with two tickets appears twice.
- The source reads `console` for a console check-in and `door` for a device scan.
- **Access:** without `admission.check-in-attendees` the page is 403. Another operator's event is 404 (a second operator on its own host holding the capability). An event with no summary is 404. There is no "module off" case, because Admission is Core and cannot be switched off. `ModuleGate::isGateable('admission')` is false, and asserting that stands in for the spec's module-off case.
- **Links (props):** the row carries `holderUserId`, `admissionId` and `registrationId`, which the page turns into `admin.users.edit`, `admin.tickets.show` and `admin.registrations.show`.
- **Roles:** `permissions:sync` creates the `admission.check-in-attendees` row, materializes `Einlass` per brand with exactly that one grant, and the operator's `Operator Admin` holds it after the sync.

---

### Task 5: Check in, check out, the ticket page (Vue-heavy)

**Requirements:** spec **§4.3** (the row action menu and dialogs), **§4.4** (the ticket detail page), **§5**'s rate limit, D-ATT-03, 04 and 05, and **§7**'s check-in, door-check, revoked, check-out and link cases, driven over HTTP.

**Files:**
- `src/Http/Controllers/Admin/ConsoleCheckInController.php` (`checkIn`, `checkOut`), `src/Http/Controllers/Admin/TicketDetailController.php` (`show`).
- `src/Data/{ConsoleCheckInData,TicketDetailData,CheckInHistoryRowData}.php`. `ConsoleCheckInData::rules()`: `conditions` array max 20, `conditions.*.key` string max 64, and `conditions.*.outcome` in `confirmed,refused`, mirroring `EntranceController::scans()`.
- Routes: `GET /tickets/{admission}` → `admin.tickets.show`. `POST /tickets/{admission}/check-in` → `admin.tickets.check-in`. `POST /tickets/{admission}/check-out` → `admin.tickets.check-out`. Both POSTs use `throttle:admission-console-check-in`.
- `AdmissionServiceProvider::boot()` gains `configureRateLimiting()`: `RateLimiter::for('admission-console-check-in', …Limit::perMinute(60)->by('admission-console-check-in:'.$userId))`, as Competition's `competition-participant` limiter does.
- `resources/js/pages/admin/tickets/Show.vue`, `resources/js/components/attendees/{CheckInDialog,CheckOutDialog,AttendeeRowActions}.vue`, and the row menu wired into Task 4's `Index.vue`.
- `tests/ConsoleCheckInRoutesTest.php`, `tests/TicketDetailPageTest.php`.

**Interfaces produced:**
- The controllers only translate. The capability comes first (`Gate::authorize(Permission::CheckInAttendees->value)`), then `Admission::withoutBrandScope()->findOrFail()`, then `ensureOperatorOwns($admission->brand_id)`, then `CheckInFromConsole`. A `ConsoleCheckInRefusedException` or a rejected `CheckIn` becomes `ValidationException::withMessages(['check_in' => sentence])`: the dialog shows the sentence, and nothing is ever a 500 (§4.3). Success flashes a toast and returns `back()`.
- `TicketDetailData` holds the reference, state, validity, holder and buyer (`userId`, name, `@username`), the ticket type, `doorChecks` (every `entry_conditions` entry with its latest recorded outcome, or `null`), `history` (`CheckInHistoryRowData`: `scannedAt`, `direction`, `result`, `reason` (sentence), `checkpointName`, `deviceLabel`, `actorName`), the registration (`id`, `typeName`, `status` from `RegistrationSummary`, or null for a crew ticket), `badgeUrl` (only when the viewer holds `admission.print-badge`, pointing at the existing `admin.admissions.badge`), and `canCheckIn`/`canCheckOut`/`owedDoorChecks`, computed by the same rules the list uses.
- `CheckInDialog.vue` lists each owed check with Confirm / Refuse (a radio pair per check, required). It shows ADR-0066's line: "Refusing records the refusal; the check-in still goes through, as at the door. Cancel if you are not letting them in." For an unassigned ticket it adds: "No holder account: the live hub will not open for anyone." It is disabled for revoked tickets and while the ticket is in. The dialog forms get no unsaved-changes guard (they are modal actions, not edit forms).

**The boundary that bites:** the check-in is decided on the server, never in the dialog. A client that ignores the disabled button must get the same refusal: revoked leads to the core's rejected row and its sentence, and already-in leads to `ConsoleCheckInRefusedException`. The tests post directly, not through the UI.

**Tests:**
- A POST check-in with the capability gives 302 back, an accepted `CheckIn` whose `actor_user_id` is the admin, and the holder's `EventPresence.hub_access = true`. A check-out gives `last_direction = out`.
- Posted door checks, confirmed and refused, are stored on the row. A missing owed answer returns a validation error and no row is written.
- A revoked ticket returns a validation error whose message equals `CheckInRejectionReason::AdmissionNotValid->consoleSentence()`, and one rejected row. An already-in ticket returns a validation error and no new row.
- Without the capability both POSTs are 403. Another operator's ticket gives a 404 on the page and on both POSTs (valid payloads, so the 404 is the scope, not validation).
- The 61st POST in a minute is 429 (the derivation in the comment: limit 60 per user per minute).
- The ticket page shows the history in `scanned_at` order with door and console rows distinguished, the door checks with their answers, the registration link props (`registrationId`), holder/buyer `userId`s, and no badge URL without `print-badge`.
- After a check-in, the list row reads `in` / `console`. Use `forgetScopedInstances()` between the POST and the GET.

**Docs (close the issue):** Admission's `SPEC.md` needs several updates. Under Owns, check-in records now carry the actor and condition outcomes, the checkpoint gets `kind`, and `RegistrationSummary` is added. The public contract gets `ConsoleCheckInRecorded` and the widened `registration_id`. Events consumed gains the three Registration events. Capabilities gains `CheckInAttendees` and Einlass. `TODO.md` gets ticked items plus follow-ups: the ADR-0029 per-brand console gap, moving `AdmissionCheckedIn` to `afterCommit` as a separate change, and the unaudited list view. Registration's `SPEC.md` is covered by Task 2. `docs/operations.md` gets one paragraph under the entrance section: the console desk, the Einlass role caveat, the metric.

---

## Ordering

1 → 2 → 3 → 4 → 5. Task 1 comes first and alone, because every later task writes through its core, and its reviewer gate is the empty diff on the nine named test files. Task 2 does not depend on Task 1 and could run beside it, but it touches `AdmissionServiceProvider::registerListeners()`, which Task 3 also edits, so keep them sequential. Task 3 needs both. Tasks 4 and 5 are Vue-heavy, run in the main checkout (as does everything here), and need `npm run build` before their Inertia tests can resolve the pages (`ensure_pages_exist` is on). Task 4 creates `Index.vue`, and Task 5 creates `Show.vue` and adds the actions to `Index.vue`.

## Self-review against the spec

| Spec section | Task |
|---|---|
| §1 problem, §2 D-ATT-01 one row per ticket | 4 |
| D-ATT-02 / §4.2 one search over every field | 4 |
| D-ATT-03 / §4.3 row actions and dialogs | 5 (UI and HTTP), 3 (rules) |
| D-ATT-04 door checks answered in the dialog | 3 (`OwedDoorChecks`, validation, storage), 5 (dialog) |
| D-ATT-05 user link, new ticket page with registration details | 4 (links), 5 (page), 2 (registration details) |
| D-ATT-06 / §5 capability, Einlass role, event admins | 4 (Brand Admin and Operator Admin get it through `everything` and the full-catalogue sync; Event has no default role to extend) |
| D-ATT-07 Admission owns it | all |
| D-ATT-08 / §3.1 one shared core | 1 (split), 3 (`CheckInFromConsole`) |
| §3 ticket type label, short ticket reference | 2 |
| §3.1 `actor_user_id`, audit | 1 (column), 3 (audit event) |
| §3.2 ADR-0033 amendment | 3 |
| §4.1 tab, columns, sort, filters | 4 |
| §4.4 ticket detail | 5 |
| §5 capability first, operator scope, module gate, rate limit | 4, 5 (the module gate resolves to "Core, always on") |
| §6 actor GDPR, console checkpoint `kind`, no new person data | 3 |
| §7 every test bullet | 1 (refactor stays green), 3 (core behaviour), 4 (search, access, links), 5 (HTTP) |

**Spec gaps resolved while writing (recorded here, not silently):**
- *"The module gate as the admission console already does"* / *"module off → 404"*: Admission is Core (`$gateable = false`) and the existing admission console has no gate. There is nothing to switch off, and the test asserts that instead.
- *"The event-administration default role also grants it"*: Event ships no default role, and `DefaultRole::of()` refuses a foreign capability. Brand Admin (`everything`) and Operator Admin already get every new case. Agenda documented the same reading for its Programme Editor.
- *Einlass on the console*: console capabilities resolve against the operator's team (ADR-0029), so a brand-scoped Einlass holder cannot open the page yet. It is built as specified and flagged in TODO. This is the controller's call to raise with the operator.
- *"`entry_conditions` not yet answered for this event day"* and *"the door checks with their answers"*: Admission recorded no outcome anywhere, since `conditions_checked` only rode on the event. It is now stored on the accepted `check_ins` row (Task 1). ADR-0066 §5 says "the scan records an outcome", and this is that record.
- *A refused door check at the console*: it still checks in, because that is what the door does (`EntryConditionsAtTheDoorTest`). The dialog says so.
- *"Already in → check-in disabled"* is also enforced on the server, as a refusal with no row. The door has no such rule, so nothing door-equivalent is recorded.
- *Crew tickets*: they appear in the list and would crash the shared core on a `TypeError`. The fix widens `AdmissionCheckedIn::$registrationId` (Task 3).

**Not in this plan** (spec §8, plus what this plan deliberately leaves): the door app as a camera PWA and device enrolment/revocation (#328, which must use `Checkpoint::doors()`); bulk check-in; CSV export; printing badges from the list (the detail page links the existing badge route); people who hold no ticket; claimed tickets entering competitions (#330); per-brand console roles (ADR-0029's future work); auditing the list *view* through `AuditRecorder` (not asked for, recorded in TODO); moving `AdmissionCheckedIn`/`CheckInRejected` to `DB::afterCommit()` (a door behaviour change, recorded in TODO); a trigram index (spec §4.2 asks for a measured need first).

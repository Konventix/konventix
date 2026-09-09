# Seating: plan editor, seat picker and seat claims inside Resources

Date: 2026-09-10. Status: approved design (question round answered 2026-09-10 01:xx), awaiting spec review by the operator in the morning; deviations found overnight go into §13.
Issues: #80 (umbrella); slices #271 (S0 Vorarbeiten), #272 (S1 schema), #273 (S2 save pipeline), #274 (S3 grant and claims), #275 (S4 console), #276 (S5 picker), #277 (S6 observability and docs), #278 (S7 editor); #279 (Phase 3 admin reseat), #280 (BFSG check, human), #281 (deferred spec items); neighbours #28, #52, #73, #70, #104, #105.
Supersedes: `SeatingSPEC.md` Draft v1 (2026-09-09) at the multi-repo root. This document is v2 of that spec, re-homed onto the codebase.

## 1. Goal

A brand's operator draws the hall plan of an event in the console (zones, groups, places, fixtures, annotations, in millimetres), publishes it, and every confirmed registration whose type grants seating picks its place from "Meine Registrierung" on a phone. Two people can never hold the same place. Cancellation, rejection and plan changes release places deterministically. Everything lives in the Resources module: a place is a unit-mode Resource, a category is a ResourceType, a booking is a ResourceBooking.

## 2. Facts the design rests on

Verified 2026-09-10 in `konventix-poc` (branch `seating-2026-09-10` off `develop` 5fa325e) and in GitLab.

- Modules live in `app-modules/<name>` (InterNACHI modular). There is no `app/Contexts`. `docs/architecture/module-map.md` assigns Seating to Resources ("a seat is one resource type with a time dimension").
- Resources today: `resource_types` (event_id, name, slug, mode unit|quantity, hold_minutes), `resources` (resource_type_id, venue_id, label, capacity, is_published), `resource_bookings` (holder_type, holder_id, registration_id, quantity, is_exclusive, state, starts_at, ends_at, hold_expires_at) with the partial exclusion constraint `resource_bookings_no_overlap` (ADR-0020, ADR-0034), `resource_event_windows` projected from `EventCreated`/`EventRescheduled`. Actions `HoldResourceBooking`, `ConfirmResourceBooking`, `ReleaseResourceBooking`, `FulfilResourceClaims`. Listeners `FulfilClaimsOnRegistrationConfirmed` (module-gated) and `ProjectEventWindow` (ungated read model). Five `Permission` cases. No routes, controllers, Vue, geometry, sweep, cancel listener.
- Known defects in that code (all fixed in slice S0): `FulfilResourceClaims` idempotency is "any booking for registration_id", so amendments are dropped; unit-mode `quantity > 1` books one seat with quantity 2; `\Throwable` is swallowed; first-fit orders by label string ("A-10" before "A-2"); `ProjectEventWindow` ignores the payload timezone (ADR-0027) and looks the window up without brand; no listener on `RegistrationCancelled`, `RegistrationRejected`, `RegistrationAmended`; no hold sweep; `ResourceFactory` publishes by default while the migration default is false; TODO.md lists shipped work as open.
- Registration grants: `GrantData(kind: GrantKind, role: string, quantity: int, resourceTypeId: ?string)` in a `DataCollection` cast; `GrantKind {admission, resource_claim}`. `RegistrationConfirmed`/`RegistrationAmended` project grants as `{kind, role, quantity, resource_type_id}` scalars and carry no registrant user id. `GuardsTypeConfiguration` freezes `grants` once a type has registrations. `RegistrationRejected` carries only the registration id; `RegistrationCancelled` carries no brand id.
- Event owns `Venue` (brand-owned) and `event_venues`; there is no Floor. `EventCreated`/`EventDetailsUpdated` carry name and slug.
- Module gate: `ModuleGate::isActive()` reads the ambient brand and returns false without one. Stripe webhooks, the scheduler and the operator admin host run without a brand. Admin routes carry no `ensure-module` middleware and `BrandScope` hardens brand-owned queries to zero rows there.
- `SlotRegistry` entries are filtered by the module gate and, when a `feature` is given, by that Pennant feature; the module's feature is `module:resources`, so slots pass `module: 'resources'` and no `feature`.
- ADR-0009: JSON columns only behind typed casts; TS types generated (`composer types:generate`). ADR-0008: semantic tokens only; `eslint konventix/no-palette-colors`. ADR-0038: `useUnsavedChanges`. ADR-0045: `defaultRoles()` per module. ADR-0055: WCAG 2.1 AA on the purchase flow. ADR-0059: metric plus dashboard panel plus alert; `ObservabilityCoverageTest`.
- Redis exists in dev (compose) and on the cluster (Valkey). Tests run `CACHE_STORE=array`. No code addresses Redis directly.
- Inertia v3 is installed: `useHttp` exists for standalone JSON requests.
- LanCore (`~/git/lan-software/LanCore`): editor in `resources/js/components/seating-editor/` (`EditorCanvas.vue`, `useEditorStore.ts`), viewer in `resources/js/components/seat-plan/` (`SeatPlanScene.vue`, `useSeatPlanViewer.ts`, `geometry.ts`), picker `resources/js/pages/seating/Picker.vue`, domain `app/Domain/Seating/`.
- Cutover: code freeze Fr 18.09 18:00; sales open 21.09; C16 (22.09) expects "Sitzplatz belegt". The Eventula importer migrates users, events and orders only, no seats (operator, 2026-09-10).

## 3. Architecture

```
Console (operator host, no brand, withoutBrandScope everywhere, brand_id forceFilled from the plan/event row)
  admin/resource-types ──► ResourceType CRUD (= "Kategorie")
  admin/plans ──────────► Plan index/create/import/export/publish
  admin/plans/{plan}/edit ► Editor (SVG, one world <g transform>) ── useHttp PUT ──► SavePlan
                                                                        │ 409 stale / 422 conflicts / 200 PlanSaveResultData
                                                                        ▼
                                              resource_plans.document (PlanDocumentData cast)
                                              resource_plan_versions (one row per save)
                                              projection ──► resources (place rows; id == element id)

Registration ── RegistrationConfirmed{grants[kind=seating], registrantUserId} ──► Resources listener
                                                                                  creates resource_claims (unplaced)
Brand host ── /my/registrations/{id}/seat ──► Pick.vue (SVG + list) ── POST placeIds ──► AssignPlacesToRegistration
                                                                                          hold → confirm in one transaction
                                                                                          exclusion constraint decides races
Registration ── RegistrationCancelled / RegistrationRejected ──► release bookings, claims released
Editor save ── occupied place deleted/recategorised/blocked ──► conflict list ──► resolution release ──► claim unplaced
```

Seating is a surface of the Resources module: namespace `Konventix\Resources`, flat module directories (`src/Actions`, `src/Data`, `src/Models`, `src/Http/Controllers`, `resources/js/pages`, `resources/js/components/plan`). No new module, no new dependency.

Rejected: a `seating` module (cannot write `resource_bookings` without importing Resources models, ADR-0004); normalised zone/group/place tables (the editor loads and saves whole documents; the projection gives booking logic what it needs); a separate `places` table (duplicates `resources`); picking in the registration form with a hold at submit (operator decision 2026-09-10: pick after registration, which also removes every cart-clock coupling and never parks a seat for an unpaid registration).

## 4. Domain model

All new tables: ULID primary key, `brand_id` FK to brands with `BelongsToBrand`, timestamps, cross-module ids as plain `ulid` without FK (ADR-0014). New migrations are additive files dated `2026_09_10_*` under `app-modules/resources/database/migrations`; the shipped `2026_08_12_*` migrations are not edited.

### 4.1 `resource_types` (= Kategorie) — extended

| Column | Type | Note |
|---|---|---|
| `description` | text null | |
| `token` | string(32) default `chart-1` | enum `CategoryToken`: `chart-1`…`chart-5`, `accent`, `success`, `warning`, `danger`; rendered as the CSS variable of that name, never a hex |
| `symbol` | string(32) null | lucide icon key; mandatory in the picker for BFSG (state never by colour alone) |
| `sort_order` | smallint default 0 | |
| `default_shape` | json null | cast `ShapeData` (nullable); default for places of this type when the editor creates them |

`mode` stays; seat categories are `unit`. `hold_minutes` stays for clocked holds (none in v1).

### 4.2 `resources` (= Place) — extended

| Column | Type | Note |
|---|---|---|
| `plan_id` | ulid null, FK `resource_plans` restrict | null for plan-less resources (booths, devices) |
| `ref` | string(64) null | human key from the label schema, unique per plan where `deleted_at` is null (partial unique via `DB::statement`) |
| `zone_id` | ulid null | element id of the enclosing zone, derived from the parent chain |
| `group_id` | ulid null | element id of the enclosing group |
| `accessible` | bool default false | |
| `blocked` | bool default false | operator lock; availability `unavailable`; assignment refuses |
| `neighbors` | json null | cast `array` (`list<string>` of resource ids), computed on save, unused in v1 |
| `origin_id` | ulid null | reserved for copies |
| `deleted_at` | timestamp null | `SoftDeletes`; a removed place keeps its booking history |

`is_published` for plan-placed resources is derived on every save: `plan.published_at !== null`. Plan-less resources keep the manual flag. `capacity` is 1, `venue_id` copied from the plan.

### 4.3 `resource_bookings` — extended

| Column | Type | Note |
|---|---|---|
| `claim_id` | ulid null, FK `resource_claims` nullOnDelete | the seat claim this booking fulfils |
| `amendment_id` | ulid null | the registration amendment that produced the booking (idempotency key with `registration_id`) |
| `label` | string null | snapshot of the resource label at booking time for history rows |

Holder types: `registration` (existing), `operator` (new: orga reservation, `holder_id` = acting user id).

Release reasons (string on `ReleaseResourceBooking`): `registration_cancelled`, `registration_rejected`, `plan_conflict`, `repicked`, `operator_released`, `expired`.

### 4.4 `resource_plans` — new

| Column | Type |
|---|---|
| `event_id` | ulid, not null |
| `venue_id` | ulid null (v1 never sets it; no venue picker) |
| `name` | string |
| `floor_label` | string null |
| `width_mm`, `height_mm` | integer |
| `background` | json null, cast `BackgroundData` |
| `unit_display` | string(2) default `cm` (`mm|cm|dm|m`) |
| `snap_step` | integer default 100 (`1|10|100|1000`) |
| `version` | integer default 0, incremented per save |
| `document` | json, cast `PlanDocumentCast` → `PlanDocumentData` |
| `published_at` | timestamp null |
| `origin_id` | ulid null |
| `created_by` | ulid null (user; nulled on erasure) |

Unique `(brand_id, event_id, name)`.

### 4.5 `resource_plan_versions` — new

`plan_id` FK cascade, `version` int, `document` json (cast `PlanDocumentCast`), `source` string(16) enum `PlanVersionSource {editor, import, copy}`, `created_by` ulid null, `created_at`. Unique `(plan_id, version)`. Written inside the save transaction; pruned to the last 50 per plan. No diff or restore UI in v1.

### 4.6 `resource_claims` — new

The Resources-owned snapshot of a seating entitlement (decision-log D-EVT-06: an unallocated claim needs its own row, never a fake booking).

| Column | Type | Note |
|---|---|---|
| `event_id` | ulid | |
| `registration_id` | ulid | |
| `registrant_user_id` | ulid null | from the confirmation payload; authorises the picker; nulled on erasure |
| `amendment_id` | ulid null | |
| `resource_type_ids` | json, cast `array` (`list<string>`) | categories the registrant may choose from |
| `seating_mode` | string(8) | `pick` or `assign` (enum `SeatingMode` in Resources, a copy of Registration's values) |
| `quantity` | integer | max places |
| `state` | string(16) | enum `ClaimState {unplaced, placed, released}` |

Unique `(registration_id, amendment_id)` with `amendment_id` coalesced to `''` via `DB::statement`. Index `(brand_id, registration_id)`, `(brand_id, event_id, state)`.

### 4.7 `resource_event_windows` — extended

`name` string null, `slug` string null (from `EventCreated`/`EventDetailsUpdated`), so the plan console can name events without reading Event models. `ProjectEventWindow` becomes an upsert on `(brand_id, event_id)` and parses wall-clock strings with the payload timezone (ADR-0027).

## 5. Plan document (`konventix.plan/v1`)

One `PlanDocumentData` per plan, stored as JSONB behind `PlanDocumentCast`, validated by laravel-data attributes, typed in `generated.d.ts`. Keys are camelCase (laravel-data convention in this repo). Coordinates are integer millimetres, origin top-left, Y down, each element relative to its parent, rotation in degrees clockwise about the element origin. For `rect` and `polygon`, `x,y` is the top-left corner; for `ellipse` and `point` it is the centre. Snapping: `round(v / step) * step`, `step ∈ {1, 10, 100, 1000}`.

```
PlanDocumentData
  schema      string   literal 'konventix.plan/v1'
  elements    DataCollection<PlanElementData>

PlanElementData
  id          string ULID, client-minted, never changed
  kind        ElementKind  zone | group | place | fixture | annotation
  parentId    ?string
  ref         ?string      unique per plan; places get it from the label schema
  label       ?string
  layer       string = 'default'
  x, y        int mm
  rotation    float = 0
  shape       ShapeData
  locked      bool = false
  originId    ?string
  token       ?CategoryToken       zone fill / fixture fill
  // group
  layout      ?LayoutData
  labelSchema ?LabelSchemaData
  // place
  resourceTypeId ?string   required for kind place
  accessible  bool = false
  detached    bool = false  true = moved by hand, ignored by the generator
  blocked     bool = false
  // fixture
  fixtureType ?FixtureType  table | counter | stage | wall | pillar | custom
  // annotation
  text        ?string
  fontSizeMm  ?int

ShapeData      type: ShapeKind point | rect | ellipse | polygon; w, h, radius, rx, ry (int, nullable); points ?list<list<int>> (min 3 for polygon)
LayoutData     type: LayoutKind linear | grid; count ?int; rows ?int; cols ?int; spacing int; rowSpacing ?int; startIndex int = 1
LabelSchemaData row: string = '{row}'; place: string = '{row}-{n:02}'
BackgroundData mediaId: string; scaleMmPerPx: float; offsetXMm: int; offsetYMm: int
```

Allowed parents: zone → null or zone; group → null, zone or group; place → group, zone or null; fixture → null, zone or group; annotation → any. Sane defaults: LAN place `rect 800×800` (table share, no chair space), chair `rect 500×500`, table `rect 1600×800`, counter `rect 2000×600`, zone `rect` bounding the selection.

Label schema placeholders: `{row}` = group label, `{n}` and `{n:02}` = 1-based index from `startIndex`, `{col}` = column index in grid layouts. The generator writes the result into `ref` and `label` of each generated place. Regeneration counts non-detached places, appends or removes at the end and keeps existing ids; removing an occupied place is a conflict (§6.3).

Removed from v1: `meta`, `anchor`, `floor_id`, `category_slots`, `availability_mode`, `quota_ref`, `access_rule`, `corridor_width`, `power_node_id`, `network_node_id`, `resource_ref`, kinds `marker | utility_node | cable`, shape `arc`, layout `arc`. `ElementKind` and `ShapeKind` are additive enums.

## 6. Save pipeline, projection, conflicts

### 6.1 `SavePlan`

`SavePlan::handle(Plan $plan, PlanSaveData $data, ?string $actorUserId, PlanVersionSource $source): PlanSaveResultData`

`PlanSaveData { expectedVersion: int, document: PlanDocumentData, resolutions: list<PlanConflictResolutionData> }`, `PlanConflictResolutionData { placeId: string, resolution: 'release' }`.

Order:

0. `expectedVersion !== $plan->version` → throw `PlanStaleException(currentVersion)` → HTTP 409 `{ currentVersion }`.
1. Data validation (laravel-data rules) → standard 422 validation errors.
2. Structural invariants (`PlanInvariants::check()`): every `parentId` exists, no cycles, parent kind allowed, ids unique, refs unique, place has `resourceTypeId` belonging to the plan's event and brand, polygon has ≥ 3 points. Failure → 422 validation errors keyed `document.elements.<index>.<field>`.
3. Read-only diff against the projection (`PlanDiff`): places added, changed (label, ref, resourceTypeId, blocked, accessible, zone, group), removed; foreign ids (a `resources` row with this id under another plan or brand, checked `withoutBrandScope`) → 422.
4. Conflicts (`PlanConflicts::detect()`): for every removed, recategorised or newly blocked place with an occupying booking (`state in held|confirmed`) → `PlanConflictData { placeId, ref, label, reason: deleted|category_changed|blocked, bookings: list<{ bookingId, holderType, registrationId, claimId }> }`. Any conflict without a matching resolution → throw `PlanConflictException(list)` → HTTP 422 `{ conflicts: PlanConflictData[] }`, nothing written.
5. Warnings (`PlanWarnings::collect()`, non-blocking): overlapping places within a zone, place outside every zone.
6. One `DB::transaction`: `SELECT … FOR UPDATE` on the plan row, the affected `resources` rows and their occupying bookings; apply resolutions through `ReleaseResourceBooking` (reason `plan_conflict`) and set the claim to `unplaced`; upsert the projection (batched, `brand_id` forceFilled from the plan); soft-delete removed places; recompute `zone_id`, `group_id`, `neighbors`, `is_published`; write `resource_plan_versions`; `version++`; prune versions beyond 50.
7. After commit: `PlanSaved { planId, eventId, brandId, version, changedPlaceIds }` and the `ResourceBookingReleased` events collected during step 6 (dispatch after commit, never inside the nested transactions).

`ValidatePlan` (dry run) runs steps 0–5 and returns `PlanValidationData { conflicts, warnings, diff summary }` for the editor's conflict dialog.

### 6.2 Projection rules

The projection is the `resources` table (§4.2). Availability is a query over `resource_bookings`; the projection carries no booking state. Holds, confirmations and releases happen only through the three booking Actions.

### 6.3 Conflicts

"Active" means a booking in state `held` or `confirmed`. Moving a place, changing its label, shape or ref is never a conflict. Resolution `release` is the only one in v1: the booking is released with reason `plan_conflict`, the claim goes back to `unplaced`, and "Meine Registrierung" shows "please choose a seat" again. No mail (operator decision 2026-09-10). `assign_manual` (admin reseat) is the Phase 3 issue; `assign_auto` strategies, deadlines and any Registration status change are out of scope. Registration status is never touched by Resources.

### 6.4 Publish, import, export

`PublishPlan` / `UnpublishPlan` set `published_at` and re-derive `is_published` on the plan's places. Only published plans reach the picker.

`ExportPlan` returns canonical JSON of `PlanDocumentData` (keys sorted, elements sorted by id, integers without decimals) as a download from the console and via `resources:plan-export {plan}`. `ImportPlan` accepts a full document (console upload on the plan index, or `resources:plan-import {file} --event= --name=`) and runs it through `SavePlan` with source `import`; unknown ids are accepted as new, ids that exist under another plan or brand are refused. No JSON-Patch, no AI or API source, no YAML.

A committed example document with 10 groups × 16 places (`app-modules/resources/database/seeders/plans/example-160.json`) plus `PlanFactory::withGrid(int $rows, int $cols)` are the fallback for building the SXLan plan if the editor slips.

## 7. Seat claims and picking

### 7.1 Registration contract (additive)

- `GrantKind::Seating = 'seating'`.
- `GrantData` gains `resourceTypeIds: ?list<string>` (each a ULID, no FK) and `seatingMode: ?SeatingMode` (Registration enum `Pick`, `Assign`). `quantity` is the maximum number of places. `resourceTypeId` stays for `resource_claim`. There is no mode `none`; omit the grant.
- `CreateRegistrationType`/`UpdateRegistrationType` guard: a seating grant needs non-empty `resourceTypeIds` and a `seatingMode`; a type may not carry a `resource_claim` and a `seating` grant for the same resource type id.
- `RegistrationConfirmed` and `RegistrationAmended` payload grants gain `resource_type_ids: list<string>|null` and `seating_mode: string|null`; both events gain `registrantUserId: string`. Admission's listener ignores unknown kinds already.
- The freeze in `GuardsTypeConfiguration` stays. Consequence recorded in #80: SXLan types receive their seating grant when they are created, before the Eventula import and before sales open.
- Type editor: extract `RegistrationTypeGrantsCard.vue` from `Edit.vue` (565 lines) first. For a seating grant the card renders the slot `registration.type-editor.seating-fields` (Resources contributes `SeatingGrantFields.vue`, props resolved by closure: the event's categories `{ id, name, slug }`), bound to the grant with `modelValue`/`update:modelValue`. No Registration read model of resource types.

### 7.2 Resources listeners

All listeners resolve `ModuleGate`/`BrandContext` at call time inside `handle()` (`.ai/rules/listeners.md`). Gating for events that fire without an ambient brand (webhooks, scheduler): `ModuleGate::isActiveForBrand(string $brandId): bool` (new, Platform, resolves the brand `withoutGlobalScopes` and checks `module:resources`). `ShortCircuitsInactiveModule` gains `moduleActiveForBrand(string $brandId): bool`.

| Listener | Event | Behaviour |
|---|---|---|
| `FulfilClaimsOnRegistrationConfirmed` | `RegistrationConfirmed` | gated by `brandId`; `resource_claim` grants: first-fit as today but `n` separate unit bookings for quantity `n`, ordered by plan, zone, group, ref; `seating` grants: create one `resource_claims` row (`unplaced`, `registrant_user_id` from the payload) per grant, idempotent on `(registration_id, amendment_id null)` |
| `FulfilClaimsOnRegistrationAmended` | `RegistrationAmended` | same, keyed on `amendment_id` |
| `ReleaseBookingsOnRegistrationCancelled` | `RegistrationCancelled` | ungated (a release is safe and idempotent for a brand that switched the module off; recorded in ADR-0060 as the explicit ADR-0003 exception); releases every active booking of the registration with reason `registration_cancelled`, claims → `released` |
| `ReleaseBookingsOnRegistrationRejected` | `RegistrationRejected` | same, reason `registration_rejected` |
| `ProjectEventWindow` | `EventCreated`, `EventRescheduled`, `EventDetailsUpdated` | upsert with timezone-aware instants, name and slug |
| `Metrics\RecordBookingMetrics`, `Metrics\RecordPlanMetrics` | Resources events | §9.6 |

### 7.3 Assignment

`AssignPlacesToRegistration::handle(ResourceClaim $claim, list<string> $placeIds, string $actorType, ?string $actorUserId): list<ResourceBooking>`

Inside one `DB::transaction`: lock the claim row `FOR UPDATE`; refuse a `released` claim; `placeIds` sorted, distinct, `count <= quantity`, non-empty; every place must be a non-deleted, non-blocked `resources` row of a published plan of `claim.event_id` whose `resource_type_id ∈ claim.resource_type_ids`; release the claim's existing active bookings with reason `repicked`; for each place `HoldResourceBooking` (holder `registration`, `holder_id` = registration id, quantity 1, interval = `ResourceEventWindow` of the event, `registration_id`, `claim_id`) immediately followed by `ConfirmResourceBooking`; `ResourceUnavailableException` (23P01) and deadlock (40P01) become `ValidationException::withMessages(['placeIds' => …])` with the place ref in the text; claim → `placed`. Missing event window → `ValidationException` on `placeIds` ("event window not projected"). Events dispatched after commit. Actor `registrant` requires `claim.registrant_user_id === $actorUserId`; actor `operator` requires `Permission::BookOnBehalf` (used by the Phase 3 reseat UI and the artisan `resources:assign-place {registration} {ref...}`).

`ReservePlaceForOperator::handle(Resource $place, string $actorUserId): ResourceBooking` — hold plus confirm with holder `operator`; `ReleaseOperatorReservation::handle(ResourceBooking $booking)` — reason `operator_released`. Both `Permission::BookOnBehalf`. Reserving an occupied place fails through the constraint.

### 7.4 Picker

Routes on the brand host (`routes/web/resources.php`, `web`, `auth`, module gate):

| Route | Name | Handler |
|---|---|---|
| `GET /my/registrations/{registration}/seat` | `resources.seating.pick` | `SeatPickController@show` → Inertia `seating/Pick` with `SeatPickerData` |
| `POST /my/registrations/{registration}/seat` | `resources.seating.pick.store` | `SeatPickController@store` with `PickPlacesData { placeIds }` → `AssignPlacesToRegistration` → redirect to `registration.mine.show` with a flash |
| `GET /my/registrations/{registration}/seat/availability` | `resources.seating.availability` | JSON `PlanAvailabilityData` |

`{registration}` is a plain ULID; the controller loads the `ResourceClaim` by `registration_id` (brand-scoped) and authorises `registrant_user_id === auth()->id()`, 404 otherwise. Registrations without a claim have no page.

`SeatPickerData { registrationId, claim: SeatClaimData { id, resourceTypeIds, seatingMode, quantity, state }, categories: list<CategoryData { id, name, token, symbol, description }>, plans: list<PlanViewData { id, name, floorLabel, widthMm, heightMm, background, document }>, availability: PlanAvailabilityData, currentPlaceIds: list<string> }`.

`PlanAvailabilityData { eventId, generatedAt, places: array<string, PlaceState> }` with `PlaceState { free, mine, held, booked, unavailable }` (`unavailable` = blocked or unpublished). The picker polls availability every 15 s while open.

`Pick.vue` (< 300 lines, extracts components under `resources/js/components/plan/`): one plan tab per published plan, `SeatPlanScene.vue` (fixed `viewBox`, one world `<g transform>`, rAF-throttled pointer pan, wheel zoom anchored at the cursor, two-finger pinch via a pointer map, content-derived min zoom, `<pattern>` grid, `<symbol>`/`<use>` per category default shape, labels hidden below a zoom threshold), a parallel list view (zone → group → place buttons with `aria-pressed`, the keyboard path), 44 px touch targets independent of zoom, inline hints "taken" versus "not in your category" versus "limit reached", `role="status"` announcements, selection submitted through a normal form post. All places render; ineligible ones are inactive with a pattern and a text state. No holder names.

"Meine Registrierung": Resources contributes `SeatSummarySlot.vue` to the existing slot `registration.mine.show` (module `resources`, no feature): shows the current place refs or "Choose your seat" linking to `resources.seating.pick`; nothing for registrations without a claim.

### 7.5 Lifecycle summary

| Moment | Effect |
|---|---|
| Registration confirmed (free type at submit; paid type at `OrderPaid`; approval type at approval) | claim `unplaced` |
| Registrant picks | bookings `confirmed`, claim `placed` |
| Registrant re-picks | old bookings `released` (`repicked`), new `confirmed` |
| Registration cancelled or rejected | bookings `released`, claim `released` |
| Amendment adds a seating grant | second claim keyed by `amendment_id` |
| Operator deletes, recategorises or blocks an occupied place and resolves `release` | booking `released` (`plan_conflict`), claim `unplaced` |
| Waitlist conversion | ordinary confirmation, then pick |
| Type change | ADR-0036: cancel and recreate, the new registration picks again |
| Umschreibung (holder change) | not modelled; blocked by #16; a seat belongs to the registration |

## 8. Console

Operator host, `routes/admin/resources.php`, middleware from `ModuleServiceProvider` (`EnsureOperatorHost`, `auth`, `can:platform.access-admin`), `Gate::authorize(Permission::X->value)` as the first line of every controller method. Every query `withoutBrandScope()` at every relation level; every created row `forceFill(['brand_id' => …])` from the plan or event window row.

### 8.1 Categories (`ResourceType`)

`admin/resource-types` Index (DataTable via `HasDataTableQuery`, event filter from `resource_event_windows`) and Edit; `ResourceTypeData` (event id, name, slug, mode, hold minutes, description, token, symbol, sort order, default shape). Permission `ManageResourceTypes`. Nav entries "Kategorien" and "Sitzplaene" under the Resources group (`AREA_ADMIN`).

### 8.2 Plans

`admin/plans` Index (DataTable: name, event, places, version, published), create dialog (event from the window read model, name, floor label, width, height), import upload, export link, publish toggle. `PlanAdminController` with `index`, `store`, `edit`, `update` (JSON: `SavePlan`), `validate` (JSON: `ValidatePlan`), `publish`, `unpublish`, `export`, `import`, `occupancy` (JSON `PlanOccupancyData { places: array<string, { state, holderType, registrationId, bookingId }> }`), `reserve` / `unreserve` (per place). Permission `ManagePlans`; reservations `BookOnBehalf`.

### 8.3 Editor MVP

`admin/plans/Edit.vue` hosts `PlanCanvas.vue` (`SeatPlanScene` plus the editor overlay: select, marquee, drag, delete, snap, tools V/S/L/D/Space/Esc/Del), `PlanElementTree.vue`, `PlanInspector.vue` (kind-specific fields, category select, blocked checkbox, generator parameters, regenerate, reserve/release for the operator), `PlanValidationPanel.vue` (client-side invariants and warnings), `PlanConflictDialog.vue` (lists conflicts, resends with resolutions and the same `expectedVersion`), `PlanSavePill.vue` (unsaved / saving / saved / error with the server text; Ctrl+S). State in `usePlanStore.ts`: `applyMutation(label, fn)` with same-label coalescing within 500 ms, 50-step undo ring, `isDirty` feeding `useUnsavedChanges` (ADR-0038). Pure TS modules with vitest: `transform.ts` (world transform, `svgPoint`, `fitToBbox`, pinch), `geometry.ts` (bboxes, hit test, neighbours), `generator.ts` (linear/grid with label schema), `snap.ts`, `invariants.ts`. Save and validate through Inertia v3 `useHttp`. After creating a group, place it right of the plan bbox, select it and switch to the select tool (LanCore lesson). English UI strings like every console page. Raster backgrounds through the media library (`MediaPicker`); no SVG (#252).

Fallback if the editor slips: Index and Edit ship with import, export, publish and occupancy only; the example document keeps the SXLan plan reachable.

## 9. Module wiring

### 9.1 Permissions and roles

`Permission` gains `ManagePlans = 'resources.manage-plans'`. `ResourcesServiceProvider::defaultRoles()` (ADR-0045): `resources.planner` → ViewResources, ManageResourceTypes, ManageResources, ManagePlans, BookOnBehalf, ReleaseBookings; `resources.desk` → ViewResources, BookOnBehalf, ReleaseBookings. `permissions:sync` after the enum change. The picker needs no capability: ownership of the claim authorises.

### 9.2 Routes, nav, slots, schedule, commands

Registered only through `ResourcesServiceProvider`: `routes/web/resources.php`, `routes/admin/resources.php`, `registerNavigation()`, `registerSlots()` (`registration.mine.show` → `SeatSummarySlot`; `registration.type-editor.seating-fields` → `SeatingGrantFields`; both `module: 'resources'`, no `feature`), `registerSchedule()` (`resources:expire-holds` every minute with `$this->commands([...])` under `runningInConsole()`), commands `resources:expire-holds`, `resources:plan-import`, `resources:plan-export`, `resources:assign-place`. Event module gains `event:replay-details {--event=}` re-dispatching `EventDetailsUpdated` and `EventRescheduled` for existing events so `resource_event_windows` can be backfilled after the module is switched on.

### 9.3 Registration slot

`SlotRegistry` gains the slot name `registration.type-editor.seating-fields` rendered by `RegistrationTypeGrantsCard.vue` via `resolveRegisteredComponent`, with context `{ eventId }`. `Edit.vue` resolves it in `RegistrationTypeAdminController@edit` like the existing slots.

### 9.4 Events (Resources contract)

`ResourceBookingHeld`, `ResourceBookingConfirmed`, `ResourceBookingReleased` gain `eventId`, `resourceTypeId`, `registrationId` (nullable), `claimId` (nullable), `label`, `ref`; `holdExpiresAt` becomes nullable. New: `PlanSaved { planId, eventId, brandId, version, changedPlaceIds }`, `PlanPublished { planId, eventId, brandId }`, `PlanUnpublished`, `SeatClaimCreated { claimId, registrationId, eventId, brandId }`, `SeatClaimPlaced { claimId, registrationId, eventId, brandId, placeIds }`, `SeatClaimReleased`. Payloads: ULIDs and scalars only.

### 9.5 GDPR

`ResourcesGdprHandler` (registered via `registerGdprExporters()`): export the subject's claims and bookings (plan name, ref, label, state, timestamps) by `registrant_user_id`, and plan versions by `created_by`; erasure nulls `registrant_user_id`, `created_by` on plans and versions, and `holder_id` of operator reservations of that user.

### 9.6 Observability (ADR-0059)

`ResourcesMetrics` catalogue: `resource_bookings_total{brand, outcome=held|confirmed|released|expired|refused}`, `resource_plan_saves_total{brand, outcome=saved|conflict|invalid|stale}`, `resource_seat_claims_unplaced{brand}` gauge, `resource_hold_sweep_last_run_timestamp_seconds` gauge. Recorders in `src/Listeners/Metrics/`. Dashboard: a "Seating" row in `contrib/observability/src/sale.mjs` (claims unplaced, bookings by outcome, refused rate, plan saves by outcome); `npm run observability:build`. Alert `KonventixResourceHoldSweepStale` (warning, sweep older than 10 min) with a unit test case in `contrib/observability/alerts/tests/konventix.rules.test.yaml` and a runbook section in `docs/operations.md`. The vmalert-tool run uses host Docker (`docker context default`); if it prompts or fails, it becomes a hand-over item.

### 9.7 Hold sweep

`ExpireResourceHoldsCommand` (`resources:expire-holds`): `state = held AND hold_expires_at < now()` `withoutBrandScope`, sets the brand context per booking before `ReleaseResourceBooking(Expired, 'expired')` so listeners see the brand, records the heartbeat gauge. Registration-linked seat bookings are confirmed in the same transaction as the hold, so the sweep never touches them.

## 10. Guidance for future agents

- Read `CLAUDE_extra.md`, `.ai/rules/index.md` and every rule file whose glob matches; `app-modules/resources/SPEC.md` (merged with this document in S6) and `TODO.md` in the same commit.
- Booking writes only through `HoldResourceBooking`, `ConfirmResourceBooking`, `ReleaseResourceBooking`; never insert into `resource_bookings` directly. The exclusion constraint is the invariant; catch `23P01` and `40P01` in the Action, translate to a domain or validation exception.
- Plan geometry lives only in the document; booking logic reads only the projection. Never query the document for availability.
- Console code runs without a brand: `withoutBrandScope()` at every level, `forceFill(['brand_id' => …])` on every create.
- Listeners resolve scoped bindings inside `handle()`. Every listener is registered with `Event::listen` in the provider; discovery is off.
- Tests: `Event::fake([...])` with explicit classes (otherwise `BelongsToBrand`'s creating hook is swallowed); shared helpers in `app-modules/resources/tests/Helpers.php` prefixed `seating`; an assertion after a constraint violation needs the failure inside a savepoint (the nested `DB::transaction` of the Actions provides one); admin-host tests create rows before the request; `vendor/bin/sail npm run build` before a test that renders a new Inertia page.
- ADR-0060 (written in S1) records: Seating in Resources, Place = Resource, Kategorie = ResourceType, document plus projection, pick after confirmation, the two hold kinds (clocked direct holds versus in-transaction confirmed seat bookings) replacing the Resources SPEC's "every hold has a deadline", the ungated release listeners as the explicit ADR-0003 exception, D-EVT-07 settled as resource type ULIDs in grants, D-EVT-06 settled as `resource_claims`, D-EVT-13 untouched.

## 11. Testing

Module tests under `app-modules/resources/tests` unless noted; every test proves one thing listed here.

- `ResourceCancellationTest`: held and confirmed bookings released on `RegistrationCancelled` and `RegistrationRejected`; unknown registration is a no-op; works with the module off; claims → `released`.
- `ResourceAmendmentTest`: added `resource_claim` grant fulfilled, keyed by `amendment_id`; replay adds nothing; added seating grant creates a second claim.
- `ExpireResourceHoldsTest`: `travelTo` expires a clocked hold, confirmed and registration bookings untouched, heartbeat gauge set, command registered with artisan and the schedule.
- `ResourceBookingTest` additions: unit-mode quantity 2 refused in the Action; a `resource_claim` grant with quantity 2 books two seats; brand-less confirmation (webhook path) still fulfils.
- `EventWindowProjectionTest`: Europe/Berlin payload stored as the correct UTC instant; reschedule upserts by brand and event; name and slug projected; `event:replay-details` backfills.
- `PlanDocumentDataTest`: valid document passes; unknown kind, forbidden parent, cycle, duplicate ref, place without category, non-integer mm, polygon with two points rejected.
- `PlanModelTest`: cast round trip; `withGrid(10, 16)` yields 160 places; generated.d.ts contains `PlanDocumentData`.
- `PlanSaveTest`: creates resources for places (id equality), updates label/ref/category/blocked, soft-deletes removed, derives zone/group/neighbors/is_published, stale version → 409 with current version and nothing written, foreign id refused, version row written and pruned at 50.
- `PlanConflictTest`: delete, recategorise, block an occupied place → 422 list with booking ids, nothing written; with `release` → booking released, claim `unplaced`, `ResourceBookingReleased` after commit; move without category change is free; validate endpoint returns the same list without writing.
- `PlanImportExportTest`: export canonical and stable; import of the example document yields 160 published resources with unique refs; artisan commands work; foreign id refused.
- `SeatingGrantTest` (registration): Data validation, guard rules, payload projection with `resource_type_ids`, `seating_mode`, `registrantUserId`.
- `SeatClaimTest`: `RegistrationConfirmed` with a seating grant creates one `unplaced` claim per grant, idempotent; module off for the brand → no claim.
- `AssignPlacesTest`: pick creates confirmed bookings and `placed` claim; second registration on the same place gets a `placeIds` error and no booking; ineligible category, blocked place, unpublished plan, too many places, wrong event → 422; re-pick releases the old bookings with `repicked`; released claim refused; operator actor needs `BookOnBehalf`; deadlock and 23P01 map to the same error; missing event window → error text.
- `SeatPickPageTest`: page renders with `SeatPickerData` for the owner, 404 for another user and for registrations without a claim, availability states free/mine/held/booked/unavailable, poll endpoint brand-scoped.
- `RegistrationMineSeatSlotTest` (registration): slot rendered with the seat summary when a claim exists, absent otherwise, absent with the module off.
- `ResourceTypeAdminTest`: index/store/update on the admin host, permission gate, `defaultRoles()` materialised by `permissions:sync`.
- `RegistrationTypeSeatingGrantEditorTest` (registration): grants card extracted, slot component present with category options for the event, seating grant survives the round trip, guard refuses seating plus resource_claim on one type.
- `PlanAdminTest`: index/create/edit/update/validate/publish/export/import/occupancy/reserve on the admin host, permission gate, conflict and stale payload shapes.
- `ResourcesGdprHandlerTest`: export contains claims, bookings and versions of the subject; erasure nulls the user references.
- `ResourcesMetricsTest`: counters and gauges move on the events and outcomes; `ObservabilityCoverageTest` green.
- vitest: `transform.test.ts`, `geometry.test.ts`, `generator.test.ts`, `snap.test.ts`, `invariants.test.ts`, `store.test.ts`, `eligibility.test.ts`.
- Optional if S3 is on schedule: `resources:probe-seat-race` (child processes, the `commerce:probe-invoice-numbering` pattern) proving one winner for N parallel picks. The human check on two phones stays in #80.

## 12. Decisions and flagged items

Operator answers, 2026-09-10 (question round in the session):

- Q1 Home: inside `app-modules/resources`.
- Q2 Kategorie: the existing ResourceType, referenced from the seating grant by ULID exactly as `resource_claim` references `resourceTypeId` and admission grants reference a role.
- Q3 Grant: new `GrantKind::Seating`, additive.
- Q4 When: **pick after registration**, read as "after confirmation" (free types immediately, paid types after payment, approval types after approval). No picker in the registration form, no hold at submit, no cart-clock coupling. Alternative not chosen: pick right after submit while unpaid.
- Q5 Storage: JSONB document behind a typed cast, projected into `resources`, element id equals resource id, plan-level publish.
- Q6 Importer: migrates users, events and orders only; no seat import. The headless `AssignPlacesToRegistration` exists because it is the pick path, plus an artisan wrapper for the operator.
- Q7 Blocked places and orga reservations: both in v1.
- Q8 Seat and person: a seat belongs to the registration; N places for quantity N as a set; no "your seat" mail for now.
- Q9 Cuts confirmed; all deferred items tracked in one issue.
- Q10 Order: refactors, schema, save pipeline, claims and picking, console, picker, observability and docs, editor last; no limit on refactoring if time is left.
- Q11 Locking: version check plus conflict refusal; soft lock, deadline lock and audited override stay in #104.
- Q12 Frontend: no new dependencies, hand-rolled SVG, raster backgrounds, English strings, chart tokens with mandatory symbols.
- Q13 Host Docker for vmalert-tool allowed; hand-over on failure.
- Q14 GitLab: #80 stays the umbrella; slice issues in Phase 2 with weights; ticks with evidence.

Flagged for the operator (no blocking effect on the run):

- The Resources SPEC's "Hold expiry — an unconfirmed booking is a temporary hold with a deadline" is narrowed by ADR-0060 to clocked direct holds; seat bookings are confirmed in the same transaction as the hold.
- `grants` stays a frozen axis: configure seating grants on the SXLan types at creation.
- Category count versus quota is not validated (Resources cannot read quotas); the picker shows "no free places in your categories" and the operator watches `resource_seat_claims_unplaced`.

## 13. Amendments

Deviations found during implementation are appended here with date, reason and the task that introduced them.

## 14. Out of scope (one tracking issue, "Nach Event")

Floors and templates (D-EVT-13 override), plan copy Event → Event with category mapping, slotted availability and `place_slots`, admin reseat UI and `TransferResourceBooking` (Phase 3 issue), re-pick notification and deadlines, `assign_auto` strategies, group booking and neighbour use, soft edit lock and deadline lock (#104), export profiles (print PDF, PNG, safety, infra), DXF and SVG backgrounds, `anchor`/georeference, utility nodes, cables, markers, corridor and power validation, station, JSON-Patch and AI/API import, YAML CLI, hand-authored JSON Schema, occupancy names or initials (SET-F-009), CMS block, Redis document cache, viewport culling and canvas fallback, arc shapes and layouts, plans above 2,000 elements, `EventRescheduled` shifting bookings (#28), `EventArchived` and `AdmissionRevoked` handling, per-admission seat binding and Umschreibung (#16), German UI strings (#132), automated accessibility tests (#183), Plausible funnel event (#90).

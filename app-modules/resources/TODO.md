# Resources — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next

- [ ] Picker for a registration with more than one claim (an amendment
      that added a seating grant): `FindSeatClaimForRegistrant` opens the
      newest, the older claim has no page. Needs a claim switcher or one
      page over all claims.
- [ ] "Zoom to my place" and a deep link (`?place=`) into the picker, so
      the mail after a `plan_conflict` release lands the person on the
      right spot — LanCore's most used plan navigation.
- [ ] Seating S7: `PlanAdminController@edit`/`@update`/`@validate` (the plan
      editor) — the console maps `PlanStaleException` (409) and
      `PlanConflictException` (422) through their own `render()`;
      `@update` needs only `Gate::authorize` and `SavePlan`, `@validate`
      only `ValidatePlan`. `index`, `store`, `publish`, `unpublish`,
      `export`, `import`, `occupancy`, `reserve` and `unreserve` are done
      (Task 33).
- [ ] Seating S6: `RecordPlanMetrics` listens on `PlanSaved` for `resource_plan_saves_total{outcome=saved}`; the `conflict`, `invalid` and `stale` outcomes are counted where the exceptions are caught (the controller), not in the Actions.
- [ ] Seating (design `docs/superpowers/specs/2026-09-10-seating-design.md`,
      ADR-0060): the save pipeline and projection, claims and picking are
      done, and so is the category console (Task 30-32). The plan index
      (create, import, export, publish, occupancy, reservations, Task
      33-35) is done too. Still open in order: the plan editor, the picker
      on the brand host, and observability.
- [ ] Seating MR note: S1 changed `app-modules/resources/database/migrations`
      (six additive migrations), so the reviewer expects the one full-suite
      run of Task 58 in the pipeline.
- [ ] Seating, per `docs/superpowers/specs/2026-09-10-seating-design.md`:
      plans, document and projection with ADR-0060 (S1); save pipeline and
      conflicts (S2); seat claims and picking (S3); console (S4); picker
      (S5); observability, GDPR handler and the SPEC merge (S6).
- [ ] Allocation beyond first-fit: a claim takes the first free resource of
      its type, which is honest and reversible but not what an organizer
      means by "put Musterfirma near the entrance". The seat picker (S3/S5)
      covers seats; booths still first-fit.
- [ ] `EventRescheduled` must also *shift* existing whole-event bookings
      (ADR-0020 assigns Resources that job, #28); today it only re-projects
      the window. Out of scope for the seating slices (design doc §14).

## Done

- [x] Seating Task 40 (#276): `/my/registrations/{registration}/seat`
      renders every published plan of the event as an SVG scene (fixed
      viewBox, one world transform, rAF pan, cursor-anchored zoom, pinch)
      beside a list view that is the keyboard path; selection posts
      `placeIds[]` to `AssignPlacesToRegistration`; availability is
      `ComputePlanAvailability` over the projection and the occupying
      bookings, polled every 15 s through `useHttp`. `HasFreePlaceForClaim`
      is a new Action (beyond the brief's file list, `SeatPickerData` gains
      `noFreePlaces` to carry it): a claim whose categories have nothing
      free right now gets its own banner on the page rather than a
      validation error nobody sees until they try to pick — worded
      identically to the summary slot Task 41 adds, quoting the design's
      own flagged phrase ("no free places in your categories"). The
      registrant's page carries the chosen refs or the picker link through
      the registration.mine.show slot (SeatSummarySlot, SeatSummaryData).
- [x] Seating Task 39 (#276): the picker's view layer under
      `resources/js/components/plan` — `SeatPlanScene.vue` (fixed mm
      viewBox, pointer pan/pinch/wheel zoom, `defineExpose`d `svgPoint`/
      `fitToBbox` for the S7 editor overlay), `PlanShape.vue` (one element
      per shape kind), `PlanListView.vue` (the genuine keyboard path —
      real `<button>`s, `aria-pressed`/`aria-disabled`, every place the
      scene renders also here with its state as text), `PlanTabs.vue`
      (roving-tabindex WAI-ARIA tablist) and `SelectionHints.vue` (two
      `role="status"` live regions). Beyond the brief's five files:
      `PlanPlace.vue` (one rendered place — shape, hatch, symbol, label,
      hit circle), `PlanBackdrop.vue` (zones/fixtures/annotations) and
      `PlanDefs.vue` (the shared `<defs>`) split out of the scene, and
      `useScenePointer.ts` (the pointer/pinch/wheel state machine, DOM-
      touching so it stays out of Task 38's pure-module vitest coverage)
      — brings `SeatPlanScene.vue` from ~545 lines as drafted to 299,
      against the project's "extract before ~300 lines" guideline
      (CLAUDE_extra.md). Every seat state (free/mine/taken/selected/not-
      in-category/unavailable) is distinguishable by hatch pattern and
      category symbol as well as fill colour; every interactive control is
      a real `<button>` at least 44 px (`size-11`/`min-h-11 min-w-11`,
      independent of the plan's zoom via `hitRadiusWorld`). No new npm
      dependency. `vue-tsc --noEmit` and `eslint` both clean; no vitest
      here by design — Task 40's `SeatPickPageTest` is what exercises this
      view code end to end.
- [x] Seating Task 38 (#276): the pure plan modules under
      `resources/js/components/plan` — `transform.ts` (LanCore's one-world
      pan/zoom/pinch transform, ported to millimetres), `geometry.ts`
      (absolute positions, bounding boxes, hit radius, the zone → group →
      place tree), `eligibility.ts` (mirrors `AssignPlacesToRegistration`'s
      taken/wrong-category/limit-reached refusals), `tokens.ts` and
      `symbols.ts`. `geometry.ts`'s `absoluteOrigin` composes rotation up
      the parent chain the way the backend's `Plans\WorldTransform::child()`
      does, rather than summing `x`/`y`: a naive sum only agrees with it
      when every ancestor's rotation is zero, and `PlanNeighborsTest.php`'s
      rotated-group case does not catch the difference, because rotating a
      row of collinear points preserves the distances between them
      regardless of rotation direction. `geometry.test.ts` pins one concrete
      rotated world coordinate for that reason. 34 vitest tests across the
      four test files.
- [x] Carried fix: `PlanAdminController::findPlace()`'s `/** @var resource */`
      had already been silently lowercased by pint's docblock fixer — the
      same PHP `resource` pseudo-type collision documented in
      `AssignPlacesToRegistration.php`, `AssignPlaceCommand.php` and
      `tests/Helpers.php`. Fixed the same way: `Resource` imported a second
      time under the alias `BookableResource`, referenced only in the
      docblock.

- [x] Seating S5, availability (#276): `ComputePlanAvailability::handle()`
      computes the picker's per-place state — `mine`/`held`/`booked` from
      the event's occupying bookings, `unavailable` for blocked places and
      places on an unpublished plan, `free` otherwise — in two reads, never
      the plan document (design §6.2/§7.4). `FindSeatClaimForRegistrant`
      is the picker's ownership lookup: the newest non-`released` claim of
      a registration whose `registrant_user_id` is the signed-in account,
      no capability involved. `seatingPublishedPlan()`, `seatingClaim()`
      and `seatingBooking()` land in `tests/Helpers.php`, not
      `SeatPickPageTest.php`, because Task 41's slot test in Registration
      calls them too. Still open for S5: the routes, the controllers, the
      `seating/Pick` page and its plan/keyboard components, and
      `SeatSummarySlot`.
- [x] S4 (seating console, #S4, #275): `Permission::ManagePlans`, default
      roles `resources.planner` / `resources.desk` (ADR-0045); the category
      console (`admin/resource-types`) and the plan index (`admin/plans`:
      create, import, export, publish, occupancy, operator reservations);
      navigation; the `registration.type-editor.seating-fields` slot filled
      with `SeatingGrantFields`, which carries each category's **bookable**
      place count (`Resource::scopeBookable()`, #282) beside it, resolved
      from this module's own tables in the slot closure rather than handed
      to Registration to query. The editor itself is S7.

- [x] Seating Task 35: Resources contributes two admin-area navigation
      entries ("Categories" at `/admin/resource-types`, order 40; "Seating
      plans" at `/admin/plans`, order 41), each gated by its own capability
      rather than a brand — the admin host has no brand to gate on
      (ADR-0029).

- [x] Seating Task 34: the plan index page (`admin/plans/Index.vue`) lists
      name, event, places, version and publication, with `PlanCreateDialog`
      and `PlanImportDialog` in the toolbar and a publish/unpublish toggle
      plus a plain-anchor export link per row (an Inertia link would fetch
      the JSON over XHR and show it in the error modal). The editor link
      arrives with the editor itself (S7); a plan is built by import until
      then.

- [x] Seating Task 33: `PlanAdminController` (index, store, publish,
      unpublish, export, import, occupancy, reserve, unreserve) and
      `CreatePlan` (an empty `konventix.plan/v1` document at version 0,
      refusing a duplicate name per brand and event or an event the
      module doesn't know). Runs on the admin host with no brand in
      context, so every query escapes `BrandScope` explicitly (grouped
      place counts, not `withCount()`) and every created row's `brand_id`
      comes from the request or the plan it belongs to. `reserve`/
      `unreserve` need `Permission::BookOnBehalf`, everything else
      `Permission::ManagePlans`. `PlanRowData` is the list row
      (`#[TypeScript]`).

- [x] Carried fix: `ReservePlaceForOperator` nests `HoldResourceBooking`'s
      transaction exactly the way `AssignPlacesToRegistration` does but
      caught nothing at all — an operator reserving a seat someone else is
      buying at that instant hit an uncaught `DeadlockException` (a
      `PDOException` sibling of `QueryException`, not a subclass) instead of
      a clean refusal. Now catches `DeadlockException` and a race-shaped
      `QueryException` (`AssignPlacesToRegistration::isRaceFailure()`) and
      normalises both to `ResourceUnavailableException`, its own existing
      contract for "the place is taken". `OperatorReservationTest` proves it
      by throwing a real `DeadlockException` from the `ResourceBooking`
      `saving` event the actual INSERT would otherwise reach — genuine
      concurrent contention needs two live connections a Pest test cannot
      produce, the same reason `resources:probe-seat-race` exists as a
      separate command for `AssignPlacesToRegistration`.

- [x] `resources:probe-seat-race {place} {--workers=}` (seating design §11,
      #S3): real child processes race one place through
      `AssignPlacesToRegistration`; exactly one winner, one confirmed
      booking; cleans up unless `--keep`; refuses production.
      `SeatRaceProbeTest` runs it in CI. The two-phones check stays in #80.
      The first real run deadlocked a worker instead of refusing it: a
      `40P01` inside `HoldResourceBooking`'s transaction, which always nests
      inside this Action's, reaches Laravel as `DeadlockException` (a
      `PDOException`), not the `QueryException` `isRaceFailure()` reads —
      `AssignPlacesToRegistration` now catches it explicitly, the case the
      single-process suite could assert `isRaceFailure()` on but never
      actually trigger.

- [x] `Actions\AssignPlacesToRegistration` (seating design §7.3, #S3): the
      one pick path — claim locked `FOR UPDATE`, sorted distinct place ids,
      eligibility over the projection joined to published plans, old
      bookings released `repicked`, hold plus confirm per place, `23P01`
      and `40P01` translated to a `placeIds` error, `SeatClaimPlaced` after
      commit; registrant and operator actors.
      `resources:assign-place {registration} {refs*} {--brand=}` wraps it
      for the operator with the `operator` actor and no user check (design
      §13).

- [x] Seat claims (seating design §7.2, #S3): `Actions\CreateSeatClaims` on
      `RegistrationConfirmed` / `RegistrationAmended` (one `resource_claims`
      row per seating grant, `unplaced`, keyed on
      `(registration_id, amendment_id)`), `Actions\ReleaseSeatClaims` on
      cancellation and rejection, events `SeatClaimCreated`,
      `SeatClaimPlaced`, `SeatClaimReleased`.

- [x] Seating Task 21: `ReservePlaceForOperator` / `ReleaseOperatorReservation` (holder `operator`, reason `operator_released`, occupied places refused by the constraint).

- [x] Seating Task 20: `ExportPlan` (canonical JSON), `ImportPlan` (through `SavePlan`, source import, category remap), `resources:plan-import`/`resources:plan-export`, committed example document with 10 × 16 places.

- [x] Seating Task 19: `PublishPlan`/`UnpublishPlan` set `published_at`, re-derive `is_published` on the projection and emit after the commit.

- [x] Seating Task 18: conflict flow proven end to end (delete/recategorise/block refused with booking ids, release resolution frees the booking and unplaces the claim after the commit, moves are free, dry run matches).

- [x] Seating Task 17: `SavePlan` (version check, invariants, foreign ids, conflicts under lock, batched projection upsert, soft-delete, version rows pruned to 50, `PlanSaved` after commit) and the `ValidatePlan` dry run.

- [x] Seating Task 16: booking events (`Held`, `Confirmed`, `Released`) are dispatched after the outermost commit via `DB::afterCommit`.

- [x] Seating Task 15: read-only plan diff and conflict detection, the save/validate payloads, `PlanStaleException` (409) and `PlanConflictException` (422), `PlanSaved`/`PlanPublished`/`PlanUnpublished`, operator holder type.

- [x] Seating Task 14: plan geometry (world transform, boxes), neighbour and warning computation, canonical JSON codec; shared `seating*` test helpers.

- [x] Seating Task 12/13 — S1 complete: `Plan`, `PlanVersion` and
      `ResourceClaim` models with their factories
      (`PlanFactory::withGrid()`, `PlanVersionFactory::forPlan()`,
      `ResourceClaimFactory`); `Resource` gains `SoftDeletes`, the plan
      projection columns and a `bookable()` scope (published, not blocked)
      that `FulfilResourceClaims`'s first-fit now uses, in plan/zone/group/ref
      order; `ResourceType` casts `default_shape` to `ShapeData`; the three
      booking events finally carry `ref` instead of S0's placeholder; the
      five model-backed Data classes deferred from Task 11 (`CategoryData`,
      `ResourceTypeData`, `PlanData`, `PlanViewData`, `SeatClaimData`) close
      out `SeatPickerData`'s ten deferred phpstan errors. SPEC.md narrows
      "Hold expiry" to clocked direct holds (ADR-0060 point 6) and names
      `Plan`/`ResourceClaim` under "Owns".

- [x] Seating Task 11 — the plan document Data classes: `PlanDocumentData`
      (`SCHEMA = 'konventix.plan/v1'`), `PlanElementData`, `ShapeData`,
      `LayoutData`, `LabelSchemaData`, `BackgroundData`, and the save,
      conflict, availability, occupancy and picker payloads
      (`PlanSaveData`, `PlanConflictResolutionData`, `PlanConflictData`,
      `PlanConflictBookingData`, `PlanWarningData`, `PlanDiffSummaryData`,
      `PlanValidationData`, `PlanSaveResultData`, `PlanAvailabilityData`,
      `PlanOccupancyData`, `PlanOccupancyPlaceData`, `PickPlacesData`,
      `SeatPickerData`) — nineteen `#[TypeScript]` Data classes in all, the
      five model-backed ones (`CategoryData`, `ResourceTypeData`, `PlanData`,
      `PlanViewData`, `SeatClaimData`) deferred to Task 12 after the models
      exist. `PlanInvariants::check()` (`src/Plans/`) is the structural
      guard shape validation cannot express — parents, cycles, unique ids
      and refs, category membership, polygon size — and returns a
      `list<array{path, message}>` keyed `document.elements.<index>.<field>`
      rather than throwing, so `SavePlan` (S2) can report every violation
      at once.

- [x] Seating Task 10 — the seating schema: `resource_plans`,
      `resource_plan_versions` and `resource_claims`; `resource_types`
      gains `description`, `token`, `symbol`, `sort_order`, `default_shape`;
      `resources` becomes the projection of a plan (`plan_id`, `ref`,
      `zone_id`, `group_id`, `accessible`, `blocked`, `neighbors`,
      `origin_id`, `SoftDeletes`) with the partial `resources_plan_ref_unique`
      index (`WHERE deleted_at IS NULL`) that Blueprint cannot express;
      `resource_bookings.claim_id` (added in Task 3) gets its FK and
      `(claim_id, state)` index here, without re-adding the column. The ten
      enums the document and the claims speak in
      (`CategoryToken`…`PlanWarningCode`), each `#[TypeScript]`. Model casts
      for the new JSON columns arrive with the models in Task 12.

- [x] Seating Task 7 — `resources:expire-holds` (`Actions\ExpireResourceHolds`)
      every minute: lapsed clocked holds become `expired` with the
      booking's brand in context, the last run is kept under
      `ExpireResourceHolds::LAST_RUN_CACHE_KEY` for the S6 heartbeat gauge.

- [x] Seating Task 6 — `ReleaseBookingsOnRegistrationCancelled` and
      `ReleaseBookingsOnRegistrationRejected` release every active booking
      of the registration through `ReleaseBookingsForRegistration`, ungated,
      with the booking's own brand in context while releasing. #105 closed
      on the Resources side.

- [x] Seating Task 5 — `FulfilResourceClaims` is idempotent per
      `(registration_id, amendment_id)`, books `n` separate unit resources
      for quantity `n` in natural label order (`A-2` before `A-10`), and
      catches only `ResourceUnavailableException`. New
      `FulfilClaimsOnRegistrationAmended`; both fulfilment listeners gate
      on the payload's brand (`moduleActiveForBrand`) so the webhook path
      fulfils too.

- [x] Seating Task 4 — `HoldResourceBooking` refuses `quantity > 1` in unit
      mode (`InvalidArgumentException`, a programming error rather than an
      availability outcome), accepts `claimId`, `amendmentId` and `label`
      and snapshots the resource label; a type with `hold_minutes` 0 holds
      without a clock. `ResourceBookingHeld/Confirmed/Released` name the
      event, the type, the registration, the claim, the label and (from S1)
      the ref, so consumers never join into this module's tables.

- [x] Seating Task 3 — `resource_event_windows` carries `name` and `slug`;
      `ProjectEventWindow` is one upsert on `(brand_id, event_id)`, parses
      the wall-clock payload with its zone (ADR-0027) and listens to
      `EventDetailsUpdated` too. `resource_bookings` gained `claim_id`
      (FK arrives with `resource_claims` in Task 10), `amendment_id` and `label`.
      `ResourceFactory` defaults to unpublished like the migration and has a
      `published()` state. `tests/Helpers.php` with the `seating*` helpers.

- [x] Shipped in slice 8 as five cases; "manage availability" and "book own"
      are not cases (availability is a booking, and booking one's own needs
      no capability); `ReleaseBookings` was added instead.

- [x] Slice 8 (domain) — bookings, capacity modes and claim fulfilment.
      `resource_types` / `resources` / `resource_bookings` with ADR-0020's
      half-open `[starts_at, ends_at)` invariant as a **PostgreSQL exclusion
      constraint**, not a check in an Action — a read-then-write check loses
      to a race it cannot see, which is the ADR's whole argument. First
      `CREATE EXTENSION` in the repo (`btree_gist`); the project is now
      PostgreSQL-specific, which ADR-0020 names and accepts. The constraint
      is partial over `state IN (held, confirmed) AND is_exclusive`:
      releasing frees the slot without deleting who held it, and
      quantity-mode bookings are excluded because their invariant is a
      *sum* no constraint can express (ADR-0034) — they are guarded by a
      locked, summed check in the Action instead.
      Hold → confirm → release Actions emitting `ResourceBookingHeld` /
      `Confirmed` / `Released`; only `Confirmed` is the contract event.
      `resource_claim` grants materialized; fulfilment fails soft by design.
      `ResourceEventWindow` projects event times from `EventCreated` /
      `EventRescheduled`, because a whole-event claim needs an interval and
      Resources may not read Event's models (ADR-0004).

- [x] Write `SPEC.md` — purpose, ownership boundary, event contract.

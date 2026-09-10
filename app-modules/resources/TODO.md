# Resources — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next

- [ ] Seating S4/S7: the console maps `PlanStaleException` (409) and `PlanConflictException` (422) through their own `render()`; `PlanAdminController@update` needs only `Gate::authorize` and `SavePlan`, `@validate` only `ValidatePlan`, `@reserve`/`@unreserve` only the two operator Actions behind `Permission::BookOnBehalf`.
- [ ] Seating S6: `RecordPlanMetrics` listens on `PlanSaved` for `resource_plan_saves_total{outcome=saved}`; the `conflict`, `invalid` and `stale` outcomes are counted where the exceptions are caught (the controller), not in the Actions.
- [ ] Seating (design `docs/superpowers/specs/2026-09-10-seating-design.md`,
      ADR-0060): the save pipeline and projection, claims and picking are
      done, and so is the category console (Task 30-32). Still open in
      order: the plan index (create, import, export, publish, occupancy,
      reservations) and its editor, the picker on the brand host, and
      observability.
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

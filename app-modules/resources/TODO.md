# Resources — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next

- [x] Seat claims shrink with a reduced registration (round 4, Task 23,
      D-REG-15): `Actions\ReduceSeatClaim` +
      `ReduceSeatClaimOnRegistrationQuantityReduced` (ungated, like the
      release listeners beside it) — `resource_claims.quantity` minus the
      `seating` grants' own quantity, surplus occupying bookings released
      through `ReleaseResourceBooking` with reason
      `registration_quantity_reduced`, unattended first and newest first,
      never the whole claim. Idempotent per
      `resource_claim_reduction_receipts.(claim_id, reduction_id)`.

- [x] A seat per ticket (#292 §6): `resource_admissions` read model off
      Admission's events (`ProjectAdmission`, backfilled by
      `admission:announce-ownership`), `resource_bookings.admission_id`
      with the partial unique index `resource_bookings_one_place_per_admission`,
      `AssignPlaceToAdmission`, the per-admission picker routes and
      `AdmissionSeatController`, `Pick.vue`'s per-ticket mode (`maxPlaces`),
      per-ticket rows in `SeatSummarySlot`, and `TicketSeatSlot` on the
      ticket page.
- [x] Seat picker usable by mouse, swap on click, list behind an
      Accessibility toggle, hover labels (2026-09-21 bugfix round, Task 1):
      `useScenePointer` reports an undragged primary `pointerup` as a world
      point and the scene resolves it with the pure `hitTestPlaces`
      (nearest centre within the 44 px radius, or inside a large place's
      box) — the per-place `@click` never fired under the svg's pointer
      capture, and the enlarged invisible hit circles let the last-painted
      seat swallow a dense row's taps. A free place at the cap is the
      `swap` reason (`selectWithSwap`: oldest out, quantity 1 replaces);
      `limit-reached` and `toggleSelection` are gone. The list of places as
      buttons is off by default behind `SeatPickPanel.vue`'s
      `aria-pressed` "Accessibility" button before the scene in DOM order,
      focus moving into the list when revealed. Per-seat `<text>` replaced
      by a `<title>` tooltip and `aria-label` per place `<g>`; groups and
      fixtures get labels in `PlanBackdrop.vue` above the
      `CONTAINER_LABEL_MM` threshold. `Pick.vue` split into
      `SeatPickPanel`, `SeatPickForm` and `useAvailabilityPoll`;
      `SceneZoomControls` out of the scene. Swap guarded in
      `SeatPickPageTest` and `AdmissionSeatControllerTest`.
- [ ] Follow-ups from that round: `PlanBackdrop`'s group and fixture
      labels sit on the unrotated bbox (`geometry.ts` docblock), so a
      rotated row's name lands beside, not above, it; the editor's
      `PlanCanvas.vue` still gets a `toggle` emit from the scene on a tap
      with the pan tool (harmless, unbound) — a `claim`-less scene could
      skip the hit test entirely.
- [ ] Extract the eligibility query and race handling shared by
      `AssignPlacesToRegistration` and `AssignPlaceToAdmission` into a
      concern (#292 follow-up; the plan asked for it, the two copies are
      fifteen lines each).
- [ ] A seating grant on a type without an admission grant keeps the
      registration-level picker; forbid or explain it in the type editor
      (#292 §12 point 2).

- [x] Category-quota ceiling, the supply half (design §12b, #282): a new
      `PlaceSupplyChanged` event (`src/Events/`) fires from `SavePlan`'s
      projection (blocked/unblocked) and `ReservePlaceForOperator`/
      `ReleaseOperatorReservation` (reserved/released) — the only places
      that already change a place's bookability outside a sale.
      `PublishPlan`/`UnpublishPlan` were wired into this too and then taken
      back out (#282 correction, §13 of the design doc): each snapshots
      the plan's current unblocked count at one instant, and the two
      snapshots need not match, so treating either as a supply move could
      zero out sales already recorded on the plain "draft, size the quota,
      sell, then publish" sequence. Carries the brand, the event, the
      category, a reason kept for observability only, and
      `direction`/`places` for how many units moved which way. Registration
      listens and consumes/releases the linked quota's unit; see its own
      TODO. Left for later, deliberately out of this slice: the picker's
      and "Meine Registrierung"'s explicit "category full" state (today's
      red form field / live-poll banner stand), and an `event` label on
      `resource_seat_claims_unplaced` — both still open on #282, not solved
      by the quota link itself.
- [ ] Hand-over from the seating build (#80): the human check on two phones
      picking the same place at the same time, and the `resources:probe-seat-race`
      child-process probe if it was not written in S3.
- [ ] Phase 3: admin reseat UI and `TransferResourceBooking` (`assign_manual`
      resolution in the conflict dialog; `BookOnBehalf`).
- [ ] `EventRescheduled` must also *shift* existing whole-event bookings
      (ADR-0020 assigns Resources that job); today it re-projects the window
      and bookings made before a reschedule keep the old interval (#28).
- [ ] Soft edit lock and deadline lock for the plan editor; audited override
      (#104). v1 has the version check plus conflict refusal only.
- [ ] Platform CMS block type embedding a published plan on a brand page
      (the viewer exists; the block registration does not).
- [ ] Plan editor follow-ups (S7 shipped the MVP): drag ignores a rotated
      parent's rotation (`absolutePosition` sums offsets only); raster
      backgrounds through `MediaPicker` are not wired on the edit page;
      a zone's shape is not auto-fitted to its children; per-category
      `default_shape` is not applied when a place is created (800 × 800
      always); the marquee selects by axis-aligned bbox only.
- [ ] Multi-claim picker (found in the 2026-09-10 fix wave's whole-branch
      review, #278): `FindSeatClaimForRegistrant` and the
      `registration.mine.show` slot both take the registration's newest
      non-released claim. An amendment deliberately creates a second claim
      (design §7.5 "Amendment adds a seating grant"), so a registration
      with two has the earlier one silently unreachable — no picker page,
      no summary row, nothing to show it was ever placed or ever will be.
      Needs a page (and a slot) that lists every open claim of a
      registration, not only the latest.
- [ ] Split the plan editor's three components still over the project's
      ~300-line guideline (CLAUDE_extra.md "Keep Vue pages small; extract
      components before a page passes ~300 lines"), found in the same
      2026-09-10 fix-wave review (#278) that also found this entry
      missing: `PlanCanvas.vue` (~351 lines), `PlanInspector.vue`
      (~378) and `PlanInspectorKindFields.vue` (~401). Left oversized to
      ship the S7 editor MVP; a review ruling parked the split here rather
      than blocking the slice on it, but the entry itself was never
      written until now.

- [ ] The picker's event link (Task 17b) is not gated on landing-page
      visibility the way Event's own hero and countdown blocks are
      (`isVisibleOnLanding()`): Resources projects name and slug from
      `EventCreated`/`EventRenamed` and hears nothing about publication
      state. A confirmed registrant's event is practically always visible,
      so this stays a note until Event emits a visibility change worth
      projecting.

## Backlog

Tracked in the "Nach Event" issue (design 2026-09-10 §14): floors and
templates, plan copy between events with category mapping, slotted
availability, re-pick notification and deadlines, `assign_auto` strategies,
group booking and neighbour use, export profiles (print PDF, PNG, safety,
infra), DXF and SVG backgrounds, georeference, utility nodes and cables,
corridor and power validation, JSON-Patch and API import, occupancy names on
the plan (SET-F-009), a CMS block, a Redis document cache, viewport culling,
arc shapes and layouts, plans above 2,000 elements, `EventArchived` and
`AdmissionRevoked` handling, per-admission seat binding (#16), German UI
strings (#132), automated accessibility tests (#183), a Plausible funnel
event (#90).

## Done

- [x] A seat change stays on the picker (#317): `SeatPickController::store()`
      and `AdmissionSeatController::store()` redirect back onto their own
      picker instead of the registration or ticket page, toast unchanged.
      The form keeps the page mounted (Inertia's non-GET `preserveState`)
      and scrolled (`:options="{ preserveScroll: true }"` on `SeatPickForm`'s
      `<Form>` — the first cut's `preserve-scroll` attribute is not a `<Form>`
      prop in Inertia 3 and did nothing, so the page jumped to the top, fixed
      in batch fix round 1); `Pick.vue` takes the fresh `currentPlaceIds` and
      availability over from the new props, so the new place shows as
      `mine` and the old one free without waiting for the poll. Giving a
      seat up (`destroy()`) is started from the ticket page and still
      returns there.

- [x] The seat picker names and links its event (bugfix round 2026-09-21,
      Task 17b): `SeatClaimData.eventName`/`eventSlug` from
      `ResourceEventWindow` (Resources' own projection, ADR-0004), both
      controllers pass the window, `seating/Pick.vue`'s heading links the
      name to the landing page when a slug is known. `SeatPickPageTest`,
      `AdmissionSeatControllerTest`.
- [x] Seating (#80, S0–S6, 2026-09-10). S0 refactors: per-booking idempotency
      and `n` unit bookings in `FulfilResourceClaims`, unit-mode quantity guard,
      cancellation and rejection listeners (ungated, ADR-0060), amendment
      listener, `resources:expire-holds` with its heartbeat, timezone-aware
      event windows with name and slug, event payloads with `eventId`,
      `resourceTypeId`, `registrationId`, `claimId`, `label`, `ref`. S1 schema:
      `resource_plans`, `resource_plan_versions`, `resource_claims`, the
      extended `resource_types`, `resources` and `resource_bookings`,
      `PlanDocumentData` and its cast, `ManagePlans`, default roles, ADR-0060.
      S2 save pipeline: `SavePlan`, `ValidatePlan`, invariants, diff,
      conflicts, warnings, versions, import and export, the example document.
      S3 claims and picking: `SeatClaimCreated`, `AssignPlacesToRegistration`
      (hold plus confirm in one transaction, the exclusion constraint decides
      races), operator reservations, `resources:assign-place`. S4 console:
      categories, plans, occupancy, reservations. S5 picker: `Pick.vue`,
      `SeatPlanScene`, the list view, availability polling, the
      "Meine Registrierung" slot, the type-editor slot. S6: metrics
      (`ResourcesMetrics`, `ResourcesGauges`, booking and plan recorders),
      the Seating dashboard row, `KonventixResourceHoldSweepStale`,
      `ResourcesGdprHandler`, this SPEC merged with the design.
- [x] S7 editor backend (#278): `PlanEditorData`, `edit`/`update`/`validate`
      on `PlanAdminController` (409 stale, 422 conflicts mirrored under
      `errors.conflicts`, 200 `PlanSaveResultData`), the three routes. Both
      endpoints delegate to `SavePlan`/`ValidatePlan`; nothing is
      reimplemented. `Edit.vue` itself is a later task — the render test in
      `PlanAdminTest` stays red until it exists.
- [x] S7 editor pure modules (#278): `ulid.ts` (client-minted Crockford
      ULIDs), `snap.ts` (grid snapping), `document.ts` (tree helpers over
      the generated Data types), `invariants.ts` (client mirror of
      `PlanInvariants::check()`, same keys as the server's 422),
      `generator.ts` (linear/grid group generation and regeneration from a
      label schema), and `usePlanStore.ts` (the editor's working copy:
      `applyMutation` with same-label coalescing inside 500 ms, a 50-step
      undo ring, dirty tracking against the last *sent* snapshot). All pure
      — no Vue except the store, no DOM — and unit-tested directly.
- [x] Plan editor MVP (S7, #80): `admin/plans/{plan}/edit` with tree,
      SVG canvas overlay (select, marquee, snapped drag, delete; V/S/L/D/
      Space/Esc/Del/Ctrl+Z/Shift+Ctrl+Z/Ctrl+S), inspector with
      kind-specific fields, category select, blocked/locked flags, group
      layout with regeneration and operator reservations, client
      invariants mirroring `PlanInvariants`, four-state save pill, and the
      conflict dialog that resends with `release` resolutions and the same
      expected version. Store: one `applyMutation` entry, 500 ms same-label
      coalescing, 50-step undo ring, `isDirty` against the sent snapshot,
      ADR-0038 guard.
- [x] Slice 8 (domain) — bookings, capacity modes and claim fulfilment.
      `resource_types` / `resources` / `resource_bookings` with ADR-0020's
      half-open `[starts_at, ends_at)` invariant as a **PostgreSQL exclusion
      constraint**, partial over `state IN (held, confirmed) AND is_exclusive`;
      `btree_gist` is the first `CREATE EXTENSION` in the repo. Hold → confirm
      → release Actions emitting `ResourceBookingHeld` / `Confirmed` /
      `Released`. `resource_claim` grants materialized; fulfilment fails soft.
      `ResourceEventWindow` projected from Event's events (ADR-0004).
- [x] Capabilities shipped as five cases in slice 8, `ManagePlans` as the
      sixth in S1; "manage availability" and "book own" are not cases.
- [x] Write `SPEC.md` — purpose, ownership boundary, event contract.

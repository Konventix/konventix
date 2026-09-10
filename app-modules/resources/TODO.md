# Resources — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next

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

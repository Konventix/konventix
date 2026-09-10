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

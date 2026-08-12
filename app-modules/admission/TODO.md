# Admission — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next (Phase 1 PoC — admissions produced by the one registration flow)

(nothing outstanding for Phase 1 — see Done)

## Backlog (Phase 2 — release scope, entrance scanning is a definition-of-done item)

- [ ] Ticket token issuance, rotation, and invalidation per ADR-0033
      (decision log Round 6, D-COM-06 Option C): signature-verifiable tokens,
      no local holder list on the device.
- [ ] Entrance API per ADR-0033: validity manifest download plus a PII-free
      revocation/checked-in **delta-feed** endpoint (not a full re-download),
      scan submission, and reconciliation endpoints, with checkpoint and
      device records.
- [ ] The offline-capable entrance client — the one case where runtime
      separation is permitted (ADR-0002) — built against ADR-0033's
      zero-PII-by-default model, plus conflict handling for duplicate scans
      across devices per the event's re-entry policy (D-ADM-03).
- [ ] Check-in and check-out records with a full accepted/rejected audit trail;
      emit `AdmissionCheckedIn`, `CheckInRejected`,
      `CheckInBatchSynchronized`.
- [ ] Event-day concept integration (decision log Round 6, D-ADM-04, user
      override — new release scope): validity evaluation needs to check an
      admission against a specific event day, not just a `valid_from`/
      `valid_until` pair. Depends on Event's event-day model landing first
      (see Event's `TODO.md`).
- [ ] Claim-link assignment flow for unassigned admissions per ADR-0032
      (decision log Round 6, D-COM-09): a shareable/emailable link that lets a
      not-yet-named holder attach themselves to one of a group registration's
      holder-less placeholders.
- [ ] Badge issuance and reprinting: assets (logo, background) sourced from
      Platform's media library by ULID per ADR-0035; layout ownership is
      still an open question in `SPEC.md`.
- [ ] Holder assignment and reassignment for group and B2B bookings; revocation
      driven by `RegistrationCancelled`.
- [ ] Holder read model from Identity events, and the GDPR handler in
      `src/Gdpr/` — check-in logs are attendance data and need an explicit
      retention answer with Compliance.
- [ ] Register navigation entries with Platform (brand-area entries — the
      operator-console `event.admin.dashboard` slot contribution below is a
      different registration, not a substitute).

## Done

- [x] Write `SPEC.md` — purpose, ownership boundary, event contract.
- [x] Model `Admission` — holder (nullable at issue time), role, validity
      window (`valid_from`/`valid_until`), lifecycle state (`AdmissionState`:
      issued/assigned/revoked/expired). ULID primary key, `BelongsToBrand`,
      `registration_id`/`event_id` as plain ULID columns, no FK (ADR-0014).
- [x] Listen to `RegistrationConfirmed` and issue one `Admission` per unit of
      `quantity` on every `{kind: 'admission'}` grant
      (`IssueAdmissionsForRegistration`, registered in
      `AdmissionServiceProvider::registerListeners()`). Idempotent: guarded by
      "does this registration already have any admissions at all" via
      `withoutBrandScope()` scoped explicitly to the payload's own `brandId`,
      so a replay in any ambient brand context issues nothing new.
      Deliberately does **not** use `ShortCircuitsInactiveModule` — Admission
      is Core tier (`gateable = false`), never gated per brand, so that guard
      does not apply here; only Pennant-gated modules need it.
- [x] `tests/RegistrationCommerceImportBoundaryTest.php` — Admission never
      imports `Konventix\Commerce` at all, and imports
      `Konventix\Registration` only from its `Events\` namespace (the one
      exempted case, ADR-0004). `IssueAdmissionsForRegistration` itself takes
      plain scalars (`registrationId`, `eventId`, `brandId`, `grants` array),
      never the event object, so only the thin listener adapter needs to
      import the event class at all.
- [x] `EvaluateAdmissionValidity` — state + validity window only;
      `tests/EvaluateAdmissionValidityTest.php` asserts the Action's source
      file contains no reference to "payment" at all, plus a validity matrix.
- [x] Capability catalog in `src/Enums/Permission.php`, `ScanCheckIn`/
      `PrintBadge` kept separate from the management capabilities (not wired
      to `permissions:sync` or Policies yet).
- [x] `admission/EventStatsCard` slot contribution to Event's operator
      console dashboard (ADR-0029, ADR-0003 "slots are an activation gate
      point"): `AdmissionServiceProvider::registerSlots()` (the new
      `ModuleServiceProvider::registerSlots()` hook, mirroring
      `registerBlockTypes()`) registers the card on the
      `event.admin.dashboard` slot; `AdmissionStatsController` (`GET
      /admin/events/{event}/admission-stats`, behind
      `admission.view-admissions`) is the card's own data source — counts by
      `AdmissionState`, through the explicit `withoutBrandScope()` escape
      hatch since the admin host resolves no brand (ADR-0010, ADR-0029). Vue
      component at `resources/js/components/admission/EventStatsCard.vue`.
      `tests/AdmissionStatsTest.php` covers capability + operator-host
      enforcement and the state counts for a seeded event.

<!-- Move completed items here with the PR that finished them. -->

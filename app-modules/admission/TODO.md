# Admission — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next (Phase 1 PoC — admissions produced by the one registration flow)

(nothing outstanding for Phase 1 — see Done)

## Backlog (Phase 2 — release scope, entrance scanning is a definition-of-done item)

- [ ] The offline-capable entrance client — the one case where runtime
      separation is permitted (ADR-0002) — built against ADR-0033's
      zero-PII-by-default model, plus conflict handling for duplicate scans
      across devices per the event's re-entry policy (D-ADM-03). Slice 6.
      `AdmissionTokenCodec` is deliberately database-free so the client can
      reuse it verbatim; `RecordScan` orders its checks to match what the
      client can decide locally, so both sides give the same reason codes.
- [ ] Operator surface for the entrance chain: checkpoint/device CRUD, showing
      a device token once at enrolment, revoking a lost scanner, and the
      check-in log behind `admission.view-check-in-log`. The domain and API
      exist; there is no screen, so enrolment currently means tinker.
- [ ] Token delivery: `IssueAdmissionToken` mints KVX1 tokens but nothing
      renders one into a QR or mails it to a holder yet — that lands with
      badges (5b) and the claim flow (5c).
- [ ] Emit `TicketTokenIssued` / `AdmissionRevoked` and append revocations to
      the delta feed. The feed's `revoked` kind is defined and served but
      nothing writes it yet: revocation has no Action of its own, so today
      only `checked_in` entries appear.
- [ ] Claim-link assignment flow for unassigned admissions per ADR-0032
      (decision log Round 6, D-COM-09): a shareable/emailable link that lets a
      not-yet-named holder attach themselves to one of a group registration's
      holder-less placeholders.
- [ ] Badge template admin UI: `BadgeTemplate` and the
      `admission.manage-badge-templates` capability exist, but there is no
      screen — designing a badge currently means tinker. The print endpoint
      (`GET /admin/admissions/{admission}/badge`) does exist.
- [ ] Batch badge printing: one admission per request today, which is wrong
      for a check-in desk handing out a group booking's badges at once.
- [ ] Holder assignment and reassignment for group and B2B bookings; revocation
      driven by `RegistrationCancelled`.
- [ ] Holder read model from Identity events, and the GDPR handler in
      `src/Gdpr/` — check-in logs are attendance data and need an explicit
      retention answer with Compliance.
- [ ] Register navigation entries with Platform (brand-area entries — the
      operator-console `event.admin.dashboard` slot contribution below is a
      different registration, not a substitute).

## Done

- [x] Slice 5b — badge templates and PDF rendering (D-ADM-05). Per-event
      `BadgeTemplate` with a typed `BadgeLayoutData` cast and media by ULID
      (ADR-0035); `role` is the match key with a `role: null` fallback,
      enforced by a partial unique index because Postgres treats NULLs as
      distinct. `BadgeDocumentRenderer` mirrors Commerce's invoice pipeline
      (dompdf, inline CSS, table layout) and inlines **everything** as `data:`
      URIs — dompdf ships with remote fetching off, and turning it on would
      let any stored media reference trigger an outbound request from the
      server at render time. That needed `MediaLibrary::dataUriFor()`:
      widening the sanctioned doorway rather than reaching around it, since
      ADR-0035 says no consumer ever sees a disk or path. QR via
      `bacon/bacon-qr-code`, promoted from a transitive Fortify dependency to
      a declared one (lock unchanged — same version already installed).
      `BadgeIssuance` makes `reprint` a fact rather than a claim; the PDF
      itself is deliberately not stored.
- [x] Slice 5a — entrance chain (ADR-0033). KVX1 tokens
      (`KVX1.<kid>.<body>.<sig>`, Ed25519, body `{tid, nonce, iat, exp, evt,
      days}`) with `EntranceSigningKey` rotation that retires without
      expiring, so a rotation never invalidates a QR already issued.
      `AdmissionTokenCodec` is database-free on purpose — the Slice 6 offline
      client reuses it unchanged. Per-device bearer credentials
      (`Checkpoint` + `EntranceDevice`, hashed token, individually revocable)
      replacing LanCore's single shared integration token. Entrance API at
      `routes/api/`: signing keys, the PII-free delta feed (opaque admission
      IDs only, monotonic-sequence cursor because venue clocks are
      unreliable), and batch scan submission idempotent per `client_scan_id`.
      Check-in ledger with a full accepted/rejected trail and separate
      `scanned_at`/`synced_at`; emits `AdmissionCheckedIn` **carrying
      `registration_id`** — the ADR-0037 prerequisite that unblocks Slice 7's
      refunds — plus `CheckInRejected` and `CheckInBatchSynchronized`.
      Day-scoped validity (D-ADM-04) and the event's re-entry knob (D-ADM-03)
      both evaluate from `EntranceEventProfile`, this module's read model of
      Event, projected from `EventDaysChanged` and the new
      `EventReentryPolicyChanged` (Event published no policy at all before —
      the blocker found while building this).
      ADR-0033 required a sync cadence to be set before build: **30s poll,
      15min staleness**, per-event overridable, after which a device degrades
      to signature-only and never to a closed door.
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

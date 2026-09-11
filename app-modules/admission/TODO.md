# Admission — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next (Phase 1 PoC — admissions produced by the one registration flow)

(nothing outstanding for Phase 1 — see Done)

## Backlog (Phase 2 — release scope, entrance scanning is a definition-of-done item)

- [ ] Camera scanning in the entrance client: it accepts a token by keyboard
      wedge or paste today, which covers USB/Bluetooth scanners but not a
      tablet's camera. `BarcodeDetector` covers Android/Chrome without a
      dependency; iOS Safari would need a decode library.
- [ ] The ADR-0018 POS screen in the entrance client: separately authorized,
      online-only, calling Commerce to book a door payment. Explicitly not
      part of scan-only capabilities, and there is deliberately no combined
      "collect and admit" operation.
- [ ] Device enrolment UX: the client takes a device token and event id typed
      in by hand. A QR-based enrolment handoff from the operator console would
      remove the copy-paste step, and pairs with the checkpoint/device admin
      screens already in this backlog.
- [ ] Operator surface for the entrance chain: checkpoint/device CRUD, showing
      a device token once at enrolment, revoking a lost scanner, and the
      check-in log behind `admission.view-check-in-log`. The domain and API
      exist; there is no screen, so enrolment currently means tinker.
- [ ] Token delivery: `IssueAdmissionToken` mints KVX1 tokens but nothing
      renders one into a QR or mails it to a holder yet — that lands with
      badges (5b) and the claim flow (5c).
- [x] `AdmissionRevoked` is emitted and revocations reach the delta feed
      (ADR-0057 point 6): on `RegistrationCancelled`,
      `Actions\RevokeAdmissionsForRegistration` moves each of the
      registration's non-checked-in admissions to `AdmissionState::Revoked`,
      emits one `AdmissionRevoked` per admission, and appends a
      `KIND_REVOKED` entry to the entrance delta feed so offline doors
      (ADR-0033) stop admitting them — a checked-in admission is kept.
      `TicketTokenIssued` still has no producer.
- [ ] Deliver claim links through Messaging: `IssueAdmissionClaimLink` mints
      the link and `urlFor()` builds the signed URL, but nothing emails it —
      an organizer has to copy it out by hand. Needs an `admission.claim-link`
      message template registered in `registerMessageTemplates()`, mirroring
      `registration.waitlist-offer`, plus a payload Data class.
- [ ] Organizer UI for claim links: issue, re-issue, revoke, and see which
      placeholders are still unclaimed on a group booking.
- [ ] Organizer-side holder assignment: `AdmissionHolderAssigned` carries
      `viaClaimLink: false` for the desk case, but no Action produces it yet —
      `Permission::AssignHolder`/`ReassignHolder` remain unwired.
- [ ] Badge template admin UI: `BadgeTemplate` and the
      `admission.manage-badge-templates` capability exist, but there is no
      screen — designing a badge currently means tinker. The print endpoint
      (`GET /admin/admissions/{admission}/badge`) does exist.
- [ ] Batch badge printing: one admission per request today, which is wrong
      for a check-in desk handing out a group booking's badges at once.
- [ ] Holder assignment and reassignment for group and B2B bookings.
- [ ] Holder read model from Identity events, and the GDPR handler in
      `src/Gdpr/` — check-in logs are attendance data and need an explicit
      retention answer with Compliance.
- [ ] Register navigation entries with Platform (brand-area entries — the
      operator-console `event.admin.dashboard` slot contribution below is a
      different registration, not a substitute).

## Done

- [x] Ticket PDF, part 2 — the holder band: the QR built from
      `IssueAdmissionToken::current()` (never `rotate()`, so a
      re-download stays the same code, pinned by
      "renders the same token across two requests"), the holder's name or
      "Unassigned", the role, the valid-days range, and a door-typeable
      short code (#72 §4.2).
- [x] Ticket PDF, part 1 — `Documents\TicketDocumentRenderer` renders one
      A4 page per admission with the event band (brand logo, name, local
      wall clock with the timezone named, venue), mirroring
      `BadgeDocumentRenderer` (#72 §4). The holder band, the footer band
      and the watermark layers land next.
- [x] Slice 6 — the offline entrance client (ADR-0002's one sanctioned runtime
      carve-out). An installable PWA served at `/entrance` from its own Vite
      entry: a plain Vue app rather than an Inertia page, because Inertia needs
      a server response before it can render and this client exists for when
      there is no server. Ed25519 verification reimplemented in TypeScript
      (`lib/token.ts`) — `AdmissionTokenCodec` is database-free so the *logic*
      ports, but it calls PHP's libsodium and cannot run in a browser.
      `lib/evaluate.ts` mirrors `RecordScan`'s check order exactly so a scan
      decided at the door and the same scan reconciled later produce identical
      reason codes; `evaluate.test.ts` pins both against the PHP enum.
      IndexedDB holds keys, delta and the scan buffer, all PII-free like the
      feed. A stale feed degrades the door to signature-only and says so — it
      never closes. Service worker and manifest are served from the site root
      (`EntranceClientAssetController`) because a worker under `/build` could
      never claim `/entrance` scope, and the shell is cached NetworkFirst at
      runtime since it is a Laravel view, not a build artifact.
- [x] Slice 5c — claim links for holder-less admissions (ADR-0032).
      `AdmissionClaimLink` + `IssueAdmissionClaimLink` / `ClaimAdmission`,
      emitting `AdmissionHolderAssigned` and moving the admission to
      `Assigned` — the state that was catalogued but had no producer.
      ADR-0032 left the mechanism open ("a signed URL, a short code, an
      email-bound token"); this follows the waitlist-offer precedent already
      in the codebase — `URL::temporarySignedRoute` with the row's own
      `expires_at` as the signature expiry, so the two cannot drift apart.
      The claim burns the link **under a row lock**, re-checking claimability
      only after the lock is held, which closes the race ADR-0032 explicitly
      names as unbuilt. Links may be email-bound so forwarding does not
      transfer the seat; every failure surfaces as one 410, because
      distinguishing them would leak who else is on the booking.
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
      `PrintBadge` kept separate from the management capabilities.
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
- [x] `admission_people`, `Models\Person` and `Listeners\ProjectPerson` —
      Admission's own read model of a person's display name, projected from
      Identity's `UserRegistered`/`UserProfileUpdated`, backfilled by
      `identity:replay-profiles`; not brand-scoped (ADR-0016), mirrors
      `registration_registrants`.
- [x] `admission_event_summaries`, `Models\EventSummary` and
      `Listeners\ProjectEventSummary::handleCreated()` — Admission's own
      read model of an event's name and schedule, projected from
      `EventCreated`; sits beside `entrance_event_profiles` (ADR-0033),
      which is not extended for this. `ProjectEventSummary` now patches
      rather than replaces on `EventDetailsUpdated`/`EventRescheduled`, and
      learns the primary venue from
      `VenueAssigned`/`VenueUpdated`/`VenueUnassigned` (design §13).
- [x] `admissions.buyer_user_id`, threaded from `RegistrationConfirmed`'s
      and `RegistrationAmended`'s `registrantUserId` through
      `IssueAdmissionsForRegistration`, distinct from `holder_user_id`
      (design §13).
- [x] `FillBuyerOnRegistrationOwnershipAnnounced` closes point 5's backfill
      chain: it fills `buyer_user_id` on admissions that predate that
      column and is (and must remain) the sole listener on
      `RegistrationOwnershipAnnounced`.

<!-- Move completed items here with the PR that finished them. -->

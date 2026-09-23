# Admission — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next (Phase 1 PoC — admissions produced by the one registration flow)

- [x] The attendee list (#329, Task 4). `GET /admin/events/{event}/attendees`
      (`admin.events.attendees.index`, `AttendeeListController`,
      `Queries\AttendeeListQuery`, page `admin/attendees/Index`): one row per
      ticket with holder, buyer, type, reference, registration and today's
      check-in, one escaped `ILIKE` search over every listed field, the
      `presence` (in / not in) and `view` (unassigned / revoked) filters, and
      the Attendees button on the event dashboard. Gated by the new
      `CheckInAttendees` capability, then scoped to the admin host's operator
      (`Http\Controllers\Admin\Concerns\ScopesToOperator`, 404 otherwise).
      "In" is read on the current event day only, through
      `Support\LatestAcceptedCheckIn`, which `CheckInFromConsole` uses too
      (Task 3 review I1).
      The reference column links to the ticket page (Task 5).

- [x] The console check-in, check-out and the ticket page (#329, Task 5).
      `POST /admin/tickets/{admission}/check-in|check-out`
      (`ConsoleCheckInController`, `Data\ConsoleCheckInData`, the named
      `admission-console-check-in` limiter at 60 a minute per account) and
      `GET /admin/tickets/{admission}` (`TicketDetailController`,
      `Queries\TicketDetailQuery`, page `admin/tickets/Show`). Each row of the
      attendee list gets Check in / Check out / Show ticket
      (`AttendeeRowActions`, `CheckInDialog`, `CheckOutDialog`); the dialog
      asks every owed door check Confirm / Refuse. Every refusal is one
      sentence under `check_in`, never a 500.

- [ ] **Per-brand console desk roles** (ADR-0029): a console capability is
      resolved against the operator's team, so a brand's own door staff
      cannot be given the attendee list without operator-wide reach. Tracked
      with the Einlass role below (#334).

- [ ] Move `AdmissionCheckedIn` and `CheckInRejected` to `DB::afterCommit()`
      (`.ai/rules/src.md`) as a change of its own. They still fire
      synchronously inside the core's transaction, because moving them
      changes door behaviour, which #329 was not allowed to do.

- [ ] The attendee list and the ticket page are not audited as *views*
      (`AuditRecorder`); only the writes are (`ConsoleCheckInRecorded`).
      Not asked for in #329; decide whether reading who is on site should
      leave a trace.

- [ ] The **Einlass** default role (D-ATT-06) is deferred to #334. Console
      capabilities resolve against the operator's team (ADR-0029), every
      console route also needs `platform.access-admin`, and ADR-0045 lets a
      module declare default roles at brand scope only — so a brand-scoped
      Einlass would open no page. Until #334 lands operator-scope console
      roles (it covers Competition's Tournament Desk too), Operator Admin
      holds `CheckInAttendees` through its full grant, and nothing narrower
      exists.

- [x] The console check-in core (#329, Task 3). `Actions\CheckInFromConsole`
      re-reads the admission by event under a lock (`wrong_event`
      otherwise), refuses `in` while in and `out` while not in, checks the
      answers against `Support\OwedDoorChecks`, and writes through
      `RecordCheckIn` against the event's one console checkpoint
      (`checkpoints.kind`, `CheckpointKind`, `Checkpoint::doors()` for any
      enrolment surface, #328). `ConsoleCheckInRecorded` is auditable and
      feeds `admission_console_check_ins_total`; the GDPR handler exports and
      nulls `check_ins.actor_user_id`. ADR-0033 amended. #333:
      `AdmissionCheckedIn::$registrationId` is nullable, so a crew badge no
      longer throws at the door or the console, and Commerce skips it.
      Follow-up: the list, the ticket page and the dialog (Tasks 4 and 5).

- [x] Registration summary and one ticket reference for the attendee list
      (#329, Task 2). `admission_registration_summaries`
      (`Models\RegistrationSummary`, projected by
      `ProjectRegistrationSummary`) carries the ticket type label and the
      registration's status, off `RegistrationConfirmed`'s new trailing
      `registrationTypeName`, `RegistrationCancelled` and the widened
      `RegistrationStandingAnnounced`; `registration:announce-standing`
      backfills it. `Support\TicketReference` replaces the two private
      `doorCode()` copies in `TicketDocumentRenderer` and
      `TicketPageController`, and gives the list its `searchFragment()`.

- [x] A brand gets an entrance signing key when it is created (#287).
      Platform raises `BrandCreated`, `MintEntranceSigningKeyOnBrandCreated`
      mints through `Actions\EnsureEntranceSigningKey` — idempotent, so a
      replay never retires a key that still signs.
      `admission:rotate-signing-key {brand?} {--all}` is the other half:
      `--all` fills gaps only and runs on the deploy path from
      `konventix:migrate` and `konventix:install`, so brands that never went
      through `CreateBrand` (install, seeders, everything older than this)
      are covered too. The exception message in `IssueAdmissionToken` names a
      command that now exists. Runbook: `docs/operations.md` → "The brand's
      entrance signing key".

- [x] Entry conditions at the door (round 4, Task 33, ADR-0066 slice B3;
      D-ADM-06/07). `admissions.entry_conditions` (json, nullable, no
      backfill) stamped by `IssueAdmissionsForRegistration` from
      `RegistrationConfirmed`'s `entry_condition_keys` as opaque scalars; an
      `entry_conditions` delta entry per admission for the
      `document_at_door` ones, so an offline device knows before the queue
      arrives and the feed stays PII-free; the offline client keeps them in
      an IndexedDB store of their own (the delta store is keyed by admission
      id, so a revocation would otherwise overwrite them), shows
      *Document check — Student ID* under an accepted scan with
      confirmed/refused, and holds that scan back from the upload until it
      is answered or the next person is scanned — the outcome rides on the
      scan itself, through `conditions_checked` on `POST /api/entrance/scans`
      → `SynchronizeScanBatch` → `RecordScan` → `AdmissionCheckedIn`, and a
      replayed batch is a no-op because `RecordScan` is idempotent per
      `client_scan_id`. The ticket prints the condition name and its
      document hint; `BadgeDocumentRenderer` and the badge templates are
      untouched, pinned by a test. Nothing here reads a Registration table
      or learns that an assertion exists.
- [x] Partial revocation for a reduced registration (round 4, Task 23,
      D-REG-15): `Actions\RevokeUnitsForRegistration` +
      `RevokeUnitsOnRegistrationQuantityReduced`, idempotent per
      `admissions.reduction_id` (the subtractive twin of `amendment_id`).
      Selection is unassigned + unclaimed + unseated first, then unassigned
      with a live claim link (revoked with it); never a holder, a seated
      ticket or an accepted check-in. Too few free admissions is a refusal
      (`NotEnoughFreeAdmissionsException`) thrown inside Registration's own
      transaction, which is what stops Commerce's queued refund. Reason
      `registration_quantity_reduced`, N × `AdmissionRevoked` + N delta rows.

(nothing outstanding for Phase 1 — see Done)

## Backlog (Phase 2 — release scope, entrance scanning is a definition-of-done item)

- [ ] Self-service token rotation by the holder (design 2026-09-11 §12,
      LanCore TKT-F-024): `IssueAdmissionToken::rotate()` exists and is
      exercised by the door's own reissue path; only a participant-facing
      "this ticket is compromised, get me a new code" button is missing.
- [ ] Adopt `Platform\Documents\DocumentWatermark` in the badge and the
      invoice (design 2026-09-11 §12): the service is built so
      `BadgeDocumentRenderer` and Commerce's invoice renderer can call it
      later; neither does yet, and each still carries its own ad hoc
      watermarking if any.
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
- [x] Deliver claim links through Messaging (#16 point 1, #292 §4.6):
      `SendClaimLink` renders `admission.claim-link` (`ClaimLinkMailData`)
      to the address a bound link is meant for, with the event's banner as
      the mail's hero; an unbound link is a bearer link the buyer copies.
- [ ] Organizer UI for claim links (#16 point 2): issue, re-issue, revoke,
      and see which placeholders are still unclaimed on a group booking.
      The buyer's own version of this exists — the ticket manager on the
      registration page (`TicketManagerSlot.vue`, `BuildTicketManager`) —
      and the Actions it uses take an `operator` actor, so the console is
      a screen, not new domain code.
- [x] Holder assignment without a link (#16 point 3, #73): `AssignHolder`
      and `UnassignHolder`, `Permission::AssignHolder`/`ReassignHolder`
      checked through Platform's `PermissionHolders` for the `operator`
      actor, ownership (`buyer_user_id`) for the `manager` actor. Take-back
      refused after an accepted check-in; the seat stays with the ticket.
- [x] The ticket page (#292 §4.5): `/my/tickets/{admission}` with the
      current KVX1 token as inline SVG (`Documents\TicketQr`, shared with
      the PDF), the banner, the seat from `admission_seats`, and Resources'
      seat button through the `admission.ticket.show` slot. The invitee's
      landing page on the claim link's own signed URL.
- [x] Holder surfaces show the handle (ADR-0065, bugfix round 2026-09-21
      Task 17a): `admission_people.username` projected beside the display
      name; `MyTicketData`, `ManagedAdmissionData`, `TicketPageData` and
      `ClaimLandingData` carry `…Username` instead of `…Name`, so the ticket
      page, `/my/tickets`, the manager rows and the claim page print
      `@username` ("Unassigned"/"Not yet assigned"/"Assigned" when null) and
      never ship the real name; the PDF keeps it (a document). The ticket
      list and the claim page link the event by `eventSlug`, and the ticket
      page links its registration for the manager. Run
      `identity:replay-profiles` after deploy so existing rows learn the
      handle.
- [x] The claim-link mail names the inviter by handle (ADR-0065, D-IDP-15,
      round 4 Task 22): `ClaimLinkMailData::managerUsername` carries
      `admission_people.username`, and the `admission.claim-link` bodies in
      `AdmissionServiceProvider::registerMessageTemplates()` print
      `{{ '@'.$managerUsername }}` — `'@'.` inside the expression, because
      `@{{ … }}` is Blade's own escape. No handle (an unknown manager, or a
      row the replay has not reached) names nobody: "The organizer has
      reserved a ticket for you." / "Die Veranstalter haben …". The real
      name no longer leaves this module for a third party at all.
- [ ] `AdmissionGdprHandler` does not tombstone `admission_people` at all —
      neither the display name nor, since ADR-0065, the handle; Content's
      handler does both. Add the tombstone write there.
- [ ] `Documents\TicketDocumentRenderer` and `Http\Controllers\TicketPageController`
      format the same event band and validity range with two copies of the
      same three helpers; fold them into one presenter (#292 follow-up).
- [ ] Ticket PDF render time, the two remaining costs after the 2026-09-21
      bugfix round (below): the QR is a large inline SVG per page
      (`Documents\TicketQr`) — a compact raster, or one shared `<defs>`
      symbol, would shrink each page — and dompdf subsets the DejaVu fonts
      per document, which dominates a one-page render; both want a
      measurement first (`admission_tickets_rendered_total` has no
      duration, so time a render by hand) before either is worth doing.
- [ ] Badge template admin UI: `BadgeTemplate` and the
      `admission.manage-badge-templates` capability exist, but there is no
      screen — designing a badge currently means tinker. The print endpoint
      (`GET /admin/admissions/{admission}/badge`) does exist.
- [ ] Batch badge printing: one admission per request today, which is wrong
      for a check-in desk handing out a group booking's badges at once.
- [x] Holder assignment and reassignment for group and B2B bookings —
      the buyer's side (#292); the desk's screen is the item above.
- [ ] Holder read model from Identity events, and the GDPR handler in
      `src/Gdpr/` — check-in logs are attendance data and need an explicit
      retention answer with Compliance.
- [ ] Register navigation entries with Platform (brand-area entries — the
      operator-console `event.admin.dashboard` slot contribution below is a
      different registration, not a substitute).

## Done

- [x] The ticket page offers only its own ticket (#316): the manager's
      "Download all tickets" button left `tickets/Show.vue` (the combined
      file stays on the registration page's `TicketManagerSlot`), and
      `TicketPageController` hands `registrationId` to the manager only.
      Download rights recorded in SPEC.md; `TicketPageTest` pins the
      holder's 403 on the bundle and the buyer's full file.

- [x] "Mein Ticket" on the live hub (D-HUB-05, design 2026-09-22 §8, task
      15): `Hub\MyTicketCard` + `Data\MyTicketCardData` behind the
      `admission.my-ticket` `HubCard` registered in
      `AdmissionServiceProvider::registerHub()` — event name, role, the
      inline-SVG scan code from `Documents\TicketQr`, a relative link to
      `/my/tickets/{admission}` and the re-entry sentence for the event's
      policy. `current()`, never `rotate()`, so the hub cannot invalidate a
      code already on screen; the closure resolves the builder per request
      rather than capturing the provider in the Octane worker. The Vue side
      is `resources/js/components/hub/MyTicketCard.vue`, which imports
      nothing outside this module — `tests/Feature/HubComponentBoundaryTest.php`
      in the host suite is what keeps it that way.
- [x] Presence and hub access (D-HUB-01/02): `Models\EventPresence`
      (`admission_event_presence`) projected off an accepted scan by
      `ProjectPresenceOnAdmissionCheckedIn` (queued after commit);
      `ParticipantAdmitted`, `ParticipantPresenceChanged`,
      `ParticipantHubAccessEnded` and — from fix round 1 —
      `ParticipantAdmissionReplaced` as the public contract; access ended by
      `EndHubAccessOnEventClosed`, `EndHubAccessOnAdmissionRevoked` and
      the `admission:close-presence-windows` sweep. Presence rows
      are exported and deleted in both modes by `AdmissionGdprHandler`,
      and counted on `admission_hub_access_total{brand, outcome}`
      (`RecordPresenceMetrics`).
- [x] The ticket's event band links to the event (2026-09-21 bugfix round,
      task 8): `components/TicketEventBand.vue` takes `eventSlug` from
      `pages/admission/tickets/Show.vue` (`TicketPageData::$eventSlug`,
      already pinned in `TicketPageTest`) and wraps the band — banner, scrim
      and heading — in one `<Link>` to `event.show` named by the heading;
      without a slug it stays a plain block. No server change.
- [x] Ticket PDF banner and render speed (2026-09-21 bugfix round, task 2):
      the banner is painted as the band's `background-image` with
      `background-size: cover` — dompdf has no `object-fit`, so the
      `<img>` was stretched into the 22mm band — pinned structurally in
      `TicketDocumentTest`. Platform's `ThumbnailGenerator` cuts a
      `ticket_banner` variant at upload (cover-cropped to the band's
      210mm x 22mm shape at up to 1240px, never upscaled) beside the
      `thumb`, and the renderer asks `MediaLibrary::dataUriForPreferring()`
      for it, then `thumb`, then the original (legacy rows have no
      variant). The brand logo is resolved once per file instead of once
      per page, and `IssueAdmissionToken` memoises the signing key per
      brand for its own instance's lifetime (no container binding, so
      nothing outlives the request under Octane). Found on the way: dompdf
      rasterises a background image into a bitmap of the box's size at the
      document DPI and embeds that once per document, so the ticket sets
      `TicketDocumentRenderer::DPI = 150` — the 210mm band becomes exactly
      1240 x 130px, the strip's own size — and the template's QR box moved
      from px to mm so it keeps its printed size. No PDF caching: rendered
      fresh, stored nowhere (§4.4) — "stores no PDF on disk" still pins it.
- [x] Participant ticket document and stable token (#72, ADR-0061):
      `Documents\TicketDocumentRenderer` renders one A4 page per admission
      from the existing KVX1 token — a rendering of the access right, not a
      new credential. `IssueAdmissionToken::current()`/`::rotate()` replace
      `handle()`; a token's nonce is now stored (`token_nonce_hash`,
      `token_issued_at`) and `VerifyAdmissionToken` refuses a superseded one
      (`CheckInRejectionReason::NonceSuperseded`, finally wired after
      existing unreferenced). `Models\Person` and `Models\EventSummary`
      close two read-model gaps the design found while building this
      (2026-09-11 amendments): the ticket's holder-name fallback and its
      event band had nothing to read. SPEC.md's Owns and Consumes updated
      in the same commit.
- [x] Ticket PDF, part 4 — the TESTSYSTEM band, drawn only when
      `DeploymentEnvironment::isProduction()` is false, painted last so it
      sits above the content bands too (#72 §4.3, ADR-0053).
      `Documents\TicketDocumentRenderer` is done: event band, holder band,
      footer band, all three watermark layers. The download routes are the
      next section's work.
- [x] Ticket PDF, part 3 — the footer band and the first two watermark
      layers, through Platform's `DocumentWatermark` service: a
      low-opacity brand mark and a diagonal forensic repeat seeded from
      the admission id, with the QR kept scannable by an opaque box
      painted over it (a later, second full-page absolute sibling) rather
      than by computing where the repeat may not go (#72 §4.3). The
      operator's presence on the page is textual only — it has no logo.
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

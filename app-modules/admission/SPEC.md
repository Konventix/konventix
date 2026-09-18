# Admission — Specification

> Stable document. Describes what this module *is*. Task tracking lives in
> `TODO.md`.

## Purpose

Admission owns **one person's right to be at an event**. Where Registration is
the commercial act, an Admission is the access right it produced: a named (or
still unnamed) holder, a role, a validity window, a badge, a scannable ticket
token, and a check-in history. One Registration grants N Admissions (ADR-0005),
and the two are deliberately separate objects — LanCore's `1 ticket = 1 gamer`
assumption is the mistake this module exists to avoid. Admission also owns the
**entrance API** and the offline-capable scanning client used at the venue
door.

The rule that shapes every decision here: **check-in asks "is this admission
valid?", never "is it paid?"** (ADR-0005). Payment state belongs to Commerce,
confirmation state belongs to Registration, and the door has no business
reading either.

## Owns

- **`Admission`** — a single person's access right: holder, buyer, admission
  role (`visitor`, `exhibitor_crew`, `speaker`, `press`, `crew`, …), validity
  window, and lifecycle state (issued, assigned, revoked, expired).
  `buyer_user_id` and `holder_user_id` are two independent nullable
  columns — the registrant who bought a registration-derived admission is
  not necessarily the person who ends up holding it (design §13). References
  its originating registration by ULID only.
- **Holder assignment** — an admission may be issued unassigned (group and B2B
  bookings) and be claimed or named later.
- **`AdmissionClaimLink`** — the mechanism by which a not-yet-named person
  attaches themselves to a holder-less placeholder (ADR-0032, which settles
  *where* the placeholder lives but leaves *how* it is claimed open). A
  temporary signed route whose signature expiry **is** the row's `expires_at`,
  so URL and record cannot disagree; a row rather than a bare signed admission
  id because a link needs revocation, an audit of who claimed which slot, and
  re-issuing without reviving a forwarded one. Re-issuing supersedes the
  previous live link, and a partial unique index keeps at most one live link
  per admission. Claiming burns the link **under a row lock**, closing the
  race ADR-0032 names as unbuilt: two people who both open the same forwarded
  link cannot both win. Optionally email-bound, so forwarding does not
  transfer the seat.
- **Ticket tokens** — the scannable credential, its format, and its issuance
  and rotation through `IssueAdmissionToken::current()`/`::rotate()`
  (ADR-0061). The token is stable across repeated issuance: `current()`
  returns the admission's existing nonce, only `rotate()` mints a new one,
  and a token carrying a superseded nonce is refused at the door
  (`token_nonce_hash`, `token_issued_at`,
  `CheckInRejectionReason::NonceSuperseded`).
- **The ticket document** — one A4 PDF page per admission
  (`Documents\TicketDocumentRenderer`, mirroring `BadgeDocumentRenderer`):
  an event band, a holder band with the QR, and a footer band naming the
  operator and where the full purchase conditions live. Rendered fresh on
  every request and stored nowhere, like the badge. Watermarked by
  Platform's shared `DocumentWatermark` service — a brand mark, a forensic
  repeat seeded from the admission id, and a TESTSYSTEM band off
  production — which Admission is the first module to call.
- **Badge** — the physical or printed identity of the holder at the venue:
  what is on it, when it is issued, and when it is reprinted.
- **`BadgeTemplate`** — the per-event badge design (D-ADM-05): a typed
  `BadgeLayoutData` behind a JSON cast, with assets referenced as media-library
  ULIDs (ADR-0035), never URLs. `role` doubles as the match key — a template
  with a role serves that role, and the single `role: null` row is the event's
  fallback, enforced by a partial unique index since Postgres treats NULLs as
  distinct. Deliberately a small fixed vocabulary, not a layout engine:
  anything richer would be a second theming system competing with Platform's.
- **`BadgeIssuance`** — when a badge was printed and by whom, so `reprint` is
  a fact read from the ledger rather than a caller's claim. The rendered PDF
  is **not** stored: a badge is reproducible on demand from the admission plus
  its template, so unlike a GoBD-archived invoice (ADR-0031) keeping
  attendee-bearing documents on disk would add GDPR surface for no gain.
- **The ticket manager and the ticket page** (#292, #16) — the buyer of a
  registration is the **manager** of its admissions (`buyer_user_id`);
  whoever is named on one is its **holder** (`holder_user_id`). The
  manager names themselves (`Actions\AssignHolder`, never a third person
  without their consent), invites by address (`SendClaimLink`, the
  `admission.claim-link` mail) or by bearer link, withdraws
  (`RevokeClaimLink`) and takes a ticket back until the door has accepted
  it (`UnassignHolder`, `AdmissionHolderUnassigned`) — all from the
  `registration.mine.show` slot (`TicketManagerSlot`, built by
  `Support\BuildTicketManager`). An organizer does the same by capability
  (`Permission::AssignHolder`/`ReassignHolder`, the `operator` actor).
  Every ticket has a page, `/my/tickets/{admission}`, for holder and
  manager alike: the event band with the banner, the scan code as inline
  SVG of the *current* token (`Documents\TicketQr`, the same encoder the
  PDF uses), the seat, and Resources' seat button through the
  `admission.ticket.show` slot. The claim link's signed URL serves both the
  landing page (GET) and the claim (POST).
- **`Models\AdmissionSeat`** (`admission_seats`) — which place an admission
  sits on, projected from Resources' `ResourceBookingConfirmed`/`Released`
  once they name an admission (#292 §5); the ticket page and the PDF name
  the seat from here. No personal data.
- **`Models\Person`** — this module's own holder read model (display name
  only), projected from Identity's `UserRegistered`/`UserProfileUpdated` and
  backfilled by `identity:replay-profiles`, mirroring Registration's
  `Registrant` and Messaging's `Recipient`. Supplies the ticket's and the
  claim flow's holder-name fallback: the linked holder's display name, else
  "Unassigned" — the badge's own fallback, now shared rather than
  re-derived.
- **`Models\EventSummary`** (`admission_event_summaries`) — this module's
  own read model of an event's name, start, end, timezone, venue label and
  banner (`banner_media_id`, a Platform media ULID resolved through
  `MediaLibrary`, #292), projected from Event's `EventCreated`,
  `EventRescheduled`, `VenueAssigned`, `VenueUpdated` and
  `VenueUnassigned`, and patched (never replaced) on `EventDetailsUpdated`
  — the banner being the one column a details update may set to null,
  because both events always carry its current value. Supplies the
  ticket's event band;
  Admission may not read Event's models directly (ADR-0004), so this exists
  even though Registration keeps a similarly-shaped copy for its own mail
  path — two read models, not two owners.
- **`admissions.buyer_user_id`** — the registrant who paid for or submitted
  the registration this admission came from, threaded from
  `RegistrationConfirmed`/`RegistrationAmended` and backfilled for
  pre-existing rows by Registration's `registration:announce-ownership`.
  Distinct from `holder_user_id`: a registration-derived admission is born
  with a buyer and no holder, and stays that way until someone claims it.
- **Check-in and check-out records** — every scan, accepted or rejected, with
  checkpoint, device, operator, and timestamp. This is the audit trail of who
  was in the building. `scanned_at` and `synced_at` are separate: an offline
  device buffers, so a scan can reach the server long after the person walked
  in, and the trail must say when they actually did.
- **`Checkpoint`** — a named door at an event. **Carries no policy**: whether a
  repeat scan is a conflict or legitimate re-entry is the event's decision
  (D-ADM-03), and a checkpoint only enforces it. The absence of a policy
  column is that decision made structural (ADR-0033).
- **`EntranceDevice`** — one enrolled scanner, holding a hash of its own bearer
  token and revocable individually. Replaces LanCore's single shared
  integration token, where one leak meant re-tokening every scanner at the
  venue. Devices hold **no attendee data at all**.
- **`EntranceSigningKey`** — Ed25519 keypairs signing KVX1 tokens. Retirement
  and expiry are distinct: a retired key stops *signing* at once but keeps
  *verifying* until every token it signed has expired, so rotation never
  invalidates a QR already in an attendee's wallet.
- **The entrance delta feed** — the PII-free list of revoked and
  already-checked-in admission IDs that supplies the negative decisions a
  signature cannot make (ADR-0033 Option C). Carries **opaque IDs only** — no
  names, no hashes standing in for names. Cursor is a monotonic sequence, not
  a timestamp, because venue hardware has unreliable clocks.
- **`EntranceEventProfile`** — this module's *read model* of the two Event
  facts a door needs with no network: the event-day calendar and the re-entry
  policy. Projected from `EventDaysChanged` and `EventReentryPolicyChanged`
  (ADR-0004 forbids reading Event's models).
- **Validity evaluation** — the single authority that decides whether a
  presented token grants entry right now.
- **Revocation and blocking** — including house bans and lost-badge
  invalidation.
- **The entrance API** — the endpoints the door client uses to download the
  validity manifest, submit scans, and reconcile after reconnecting.
- **The offline-capable entrance client.** Runtime separation in Konventix is
  reserved for genuine offline requirements, and this is the one named case
  (ADR-0002, `docs/architecture/overview.md`): the door must keep working when
  the venue's uplink does not. Admission owns both sides of that sync contract.

## Does not own

| Nearby concept | Owned by |
|---|---|
| The contractual act, the `RegistrationType`, its axes and quotas | **Registration** — Admission reacts to `RegistrationConfirmed`; it never creates or judges a registration |
| Whether anything was paid, orders, invoices, refunds | **Commerce** — the door never consults payment state (ADR-0005) |
| Whether a registration is confirmed | **Registration** — confirmation is an input that produced the admission, not a condition re-checked at the gate |
| The seat, booth, room, or device the holder may use, and its booking | **Resources** — an admission may reference a booking by ULID for display, but does not own it |
| The user account, real identity, and profile of the holder | **Identity** — Admission keeps a holder read model for badge names and lookups (ADR-0004) |
| Platform authorization roles and permission grants | **Platform** plus per-brand role data (ADR-0013). An admission role is an access label at the venue, not a Gate role |
| Shift plans and duty rosters for crew | **Staffing** — a crew admission says someone may enter, not what they are rostered for |
| Event dates, venue definition, and the programme | **Event** and **Agenda** — Admission reads validity boundaries from Event |
| Sending the ticket to the holder | **Messaging** |
| GDPR export and deletion orchestration | **Compliance** — Admission contributes its own `src/Gdpr/` handler, which matters because check-in logs are attendance data |

## Public contract (events)

Events are the only way other modules may react to this one (ADR-0004).
Payloads carry ULIDs and scalars — never Eloquent models.

All rows below are **proposed**: no code exists yet, and names are expected to
firm up while the first flow is built.

| Event | Emitted when | Payload |
|---|---|---|
| `AdmissionIssued` | An admission is created from a confirmed registration's grants, or for crew | `admission_id`, `registration_id` (nullable), `event_id`, `brand_id`, `role`, `holder_user_id` (nullable), `issued_at`, `buyer_user_id` (nullable, #292 — so Resources' admission read model knows who may seat the ticket) | implemented |
| `AdmissionHolderUnassigned` | An admission loses its holder without losing its existence (#292, #73): the buyer took it back or an organizer cleared it at the desk. Refused after check-in. The seat stays with the admission | `admission_id`, `registration_id` (nullable), `event_id`, `brand_id`, `previous_holder_user_id`, `unassigned_by_user_id` (nullable), `reason_code`, `unassigned_at` | implemented |
| `ClaimLinkIssued`, `ClaimLinkSent`, `ClaimLinkRevoked` | A claim link is minted (bound to an address or a bearer link), its invitation mail handed to Messaging or refused (`sent`), or withdrawn by its issuer (#292 §9). Exist for the metrics recorder; carry `link_id`, `admission_id`, `brand_id` and the one flag each | implemented |
| `AdmissionOwnershipAnnounced` | Backfill only (#292): the admission's current holder, buyer and state, re-emitted by `admission:announce-ownership` so Resources' `resource_admissions` can catch up on rows older than that read model. **Only a projection may listen** — a test scans every provider for it | `admission_id`, `registration_id` (nullable), `event_id`, `brand_id`, `holder_user_id` (nullable), `buyer_user_id` (nullable), `state` | implemented |
| `AdmissionHolderAssigned` | An initially unassigned admission gets a named holder. `viaClaimLink` separates self-service claiming from an organizer naming someone at a desk — both end in the same state, only one means a person proved possession of a link | `admission_id`, `registration_id`, `event_id`, `brand_id`, `holder_user_id`, `via_claim_link`, `assigned_at` | implemented |
| `AdmissionReassigned` (proposed) | An admission is transferred to a different person | `admission_id`, `previous_holder_user_id`, `holder_user_id`, `reassigned_at` |
| `AdmissionRevoked` | An admission stops being valid before its window ends — emitted by `Actions\RevokeAdmissionsForRegistration` when the registration behind it is cancelled (ADR-0057 point 6) | `admission_id`, `registration_id` (nullable — a crew admission has none, D-STF-01), `event_id`, `brand_id`, `revoked_by_user_id` (nullable — the sweep-driven cancellation has none), `reason_code`, `revoked_at` |
| `TicketTokenIssued` (proposed) | A scannable token is minted or rotated | `admission_id`, `token_id`, `issued_at`, `supersedes_token_id` (nullable) |
| `BadgeIssued` | A badge is produced or reprinted at the venue. `reprint` is derived from the issuance ledger, not supplied by the caller. Carries no holder name — a run of reprints is the shape of a lost badge and worth seeing, but the payload stays PII-free | `issuance_id`, `admission_id`, `event_id`, `brand_id`, `badge_template_id` (nullable), `issued_by_user_id` (nullable), `reprint`, `issued_at` | implemented |
| `AdmissionCheckedIn` | A scan is accepted at a checkpoint. **`registration_id` is load-bearing beyond this module**: ADR-0037 blocks a refund on a checked-in admission, and D-COM-15 settles that Commerce answers that from its own read model fed by this event — never by reading Admission's tables. Slice 7's refunds were blocked until this shipped | `check_in_id`, `admission_id`, `registration_id`, `event_id`, `brand_id`, `event_day_id` (nullable), `checkpoint_id` (nullable), `direction`, `scanned_at` | implemented |
| `CheckInRejected` | A presented token is refused. A first-class event, not a swallowed error — the audit trail records why | `check_in_id`, `admission_id` (nullable — a bad signature never resolves to one), `event_id`, `brand_id`, `checkpoint_id` (nullable), `reason_code`, `scanned_at` | implemented |
| `CheckInBatchSynchronized` | A device delivered its buffered scans. `duplicates` counts scans already recorded — the normal shape of a reconnection replay, not a fault | `device_id`, `event_id`, `brand_id`, `accepted`, `rejected`, `duplicates`, `synchronized_at` | implemented |
| `AdmissionCheckedOut` (proposed) | A holder is scanned out. Recorded today as a `direction: out` check-in; a distinct event only if a consumer needs one | `admission_id`, `checkpoint_id`, `device_id`, `checked_out_at` |

## Consumes

Events from other modules that this module listens to, and the read models it
maintains from them.

| Event | Owning module | Why |
|---|---|---|
| `RegistrationConfirmed` (proposed) | Registration | The trigger: issue one admission per granted admission in the payload, with the granted role |
| `RegistrationAmended` | Registration | Grants were added to an already-confirmed registration (ADR-0036): issue **exactly the delta**. Idempotency is keyed on `amendment_id` (null for the original confirmation) rather than "does this registration have any admissions?", which would swallow every amendment |
| `EventDaysChanged` | Event | Projects the event-day calendar into `EntranceEventProfile` so a door can resolve the current day locally (D-ADM-04, ADR-0033). Replaces rather than patches — the payload always carries the complete set |
| `EventReentryPolicyChanged` | Event | Projects the re-entry knob into the same read model (D-ADM-03). Without it the door could not evaluate re-entry offline, and Admission may not read Event's models (ADR-0004) |
| `RegistrationCancelled` (ADR-0057 point 6) | Registration | Revoke the admissions that registration produced — `AdmissionState::Revoked`, one `AdmissionRevoked` each, and a `KIND_REVOKED` entry in the entrance delta feed so offline doors (ADR-0033) stop admitting them. An admission with an accepted check-in is kept: attendance happened, and money owed after the fact is Commerce's receivable, not the door's. This is the only path by which a cancellation reaches Admission; there is deliberately no listener on `PaymentReversed`, because a door that evaluates payment is ADR-0005's prohibition |
| `UserRegistered`, `UserProfileUpdated` | Identity | Maintain `Models\Person`, the holder-name read model the ticket and the badge fallback both read (ADR-0061). Backfilled by `identity:replay-profiles` |
| `EventCreated`, `EventRescheduled`, `EventDetailsUpdated`, `VenueAssigned`, `VenueUpdated`, `VenueUnassigned` | Event | Maintain `Models\EventSummary` (`admission_event_summaries`): times and timezone from the first two, venue label from the venue events, name/slug patched (not replaced) from `EventDetailsUpdated`. Backfilled by `event:replay-details`, which since this feature also re-fires `VenueAssigned` for each event's primary venue |
| `RegistrationOwnershipAnnounced` | Registration | Backfills `admissions.buyer_user_id` for rows that predate the column — the replay-safe counterpart to `RegistrationConfirmed`/`RegistrationAmended`, which already carry `registrantUserId` going forward |
| `UserAnonymizationRequested` (proposed) | Compliance | Hand the request to this module's `src/Gdpr/` handler; check-in records are attendance data and need an explicit answer |
| `ResourceBookingConfirmed`, `ResourceBookingReleased` | Resources | `ProjectAdmissionSeat` (#292 §5): maintain `Models\AdmissionSeat` (`admission_seats`) — the place bound to an admission, for the ticket page and the ticket PDF — from a *confirmed* booking that names an `admissionId`, deleted again when that booking is released. A hold never reaches a ticket; a booking naming no admission is nobody's seat here |

## Capabilities

Declared in `src/Enums/Permission.php`. Roles that grant them are per-brand
data, not code (ADR-0013).

The catalog covers: viewing admissions, issuing and revoking them, assigning or
reassigning holders, performing check-in and check-out, printing and
reprinting badges, managing checkpoints and entrance devices, and reading the
check-in log. Door staff need a narrow set — scan and print — and nothing else;
that separation is the reason the check-in capability is distinct from the
management ones.

## Activation

**Core tier: always active.** Every brand needs Admission and no Pennant
feature gates it: a brand that registers people but cannot let them in has
half a system. The module self-registers everything through
`AdmissionServiceProvider` at boot (ADR-0003).

The entrance client is deployed separately because it must work offline, but it
is not a separately activated module — it is a client of this module's
entrance API, and the API lives inside the monolith like everything else.

## Open questions

1. ~~**Offline validation model.** Whether the door client verifies a
   cryptographically signed token without any local list, or downloads a
   manifest of valid admissions and checks membership. This decides token
   format, revocation latency, and how much data sits on a device at the venue.~~
   **Settled by ADR-0033 (decision log Round 6, D-COM-06, Option C):**
   signature verification plus a PII-free revocation/checked-in delta feed.
2. ~~**PII on the device.** How much holder data an offline client may hold,
   for how long, and what happens if a device is lost — a GDPR question to
   settle with Compliance before the client is built.~~ **Settled by
   ADR-0033:** zero PII on devices by default. Holder-name display is a
   separate opt-in feature with its own Compliance decision later.
3. ~~**Duplicate and conflicting scans.** The resolution rule when two offline
   devices both accept the same token, and whether a duplicate is an error, a
   warning, or a legitimate re-entry.~~ **Settled by ADR-0033**, together with
   D-ADM-03 below: the offline model's delta feed carries checked-in state
   across devices, and whether a repeat scan is a conflict or a legitimate
   re-entry is governed by the event's re-entry policy knob, not treated as an
   error by default.
4. ~~**Re-entry policy.** Whether check-in is once-only, once per day, or
   unlimited, and whether that is a property of the admission, the checkpoint,
   or the event.~~ **Decision log Round 6 (D-ADM-03):** unlimited by default;
   **the event** carries the policy knob (once-only / once-per-day /
   unlimited). Checkpoints stay policy-free.
5. ~~**Validity windows.** Whether day-scoped and partial-event admissions are
   a first-class concept from the start, or a later addition on top of a
   simple from/until pair.~~ **Decision log Round 6 (D-ADM-04, user
   override):** first-class at release — an event-day concept with per-day
   admission validity, not just a from/until pair. Event owns the event-day
   concept; see the paired decision in Event's SPEC.
6. **Badge rendering — partially settled.** **Settled by ADR-0035:** badge
   assets (logos, backgrounds) come from Platform's media library, referenced
   by ULID. ~~**Still open:** where the badge *layout* itself lives —
   Admission templates, Platform's block/theming layer, or a print service.~~
   **Decision log Round 7 (D-ADM-05):** Admission owns per-event
   `BadgeTemplate` entities — a typed layout definition, assets referenced
   from Platform's media library by ULID (ADR-0035), server-rendered to PDF
   via the same dompdf pipeline Commerce uses.
7. ~~**Unassigned admissions.** Whether the placeholder for a not-yet-named
   group attendee is created here on confirmation or held in Registration
   until a person exists (paired with the same question in Registration's
   SPEC).~~ **Settled by ADR-0032 (decision log Round 6, D-COM-09):** Admission
   holds holder-less placeholders, issued on confirmation (blesses the Phase 1
   implementation); names attach later via claim links.

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
  `admission.ticket.show` slot. Who downloads what (#316): the single
  ticket's PDF (`/my/admissions/{admission}/ticket`) is its holder's; the
  registration's combined file (`/my/registrations/{registration}/ticket`)
  is its buyer's, offered on the registration page only. The ticket page
  offers its own ticket and nothing more, and hands `registrationId` to
  the manager alone. The claim link's signed URL serves both the
  landing page (GET) and the claim (POST). On the live hub the same code is
  the `admission.my-ticket` card (`Hub\MyTicketCard`, registered as a
  Platform `HubCard` at boot, D-HUB-05): the scan code, who it admits as
  what, and the one sentence that says whether they may walk back in —
  always `IssueAdmissionToken::current()`, never `rotate()`, because
  opening the hub must not invalidate a code already on screen or on paper.
- **`Models\AdmissionSeat`** (`admission_seats`) — which place an admission
  sits on, projected from Resources' `ResourceBookingConfirmed`/`Released`
  once they name an admission (#292 §5); the ticket page and the PDF name
  the seat from here. No personal data.
- **`Models\Person`** — this module's own holder read model (display name
  and, since ADR-0065, the handle), projected from Identity's
  `UserRegistered`/`UserProfileUpdated` and
  backfilled by `identity:replay-profiles`, mirroring Registration's
  `Registrant` and Messaging's `Recipient`. Supplies the ticket's and the
  claim flow's holder fallback: the linked holder's handle on participant
  surfaces and in the `admission.claim-link` mail (ADR-0065, D-IDP-15), the
  display name on the documents this module renders, else "Unassigned" —
  the badge's own fallback, now shared rather than re-derived.
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
- **`Models\RegistrationSummary`** (`admission_registration_summaries`) —
  this module's own read model of a registration's type id, type name and
  status, one row per registration (attendee-list design §3, §4.4, #329).
  The attendee list labels each ticket with the type from here and the
  ticket detail shows the registration's status; neither reads
  Registration's tables (ADR-0004). Projected by
  `Listeners\ProjectRegistrationSummary` from `RegistrationConfirmed`
  (`confirmed`), `RegistrationCancelled` (flips an existing row to
  `cancelled`, never creates one: the event carries no brand) and the
  backfill `RegistrationStandingAnnounced` (`registration:announce-standing`).
  A null type id or name in a payload never overwrites a stored one.
  A row the backfill wrote for a `submitted` or `approved` registration goes
  stale when that registration is later approved or rejected, because the
  projection does not listen to those events; that is harmless, since such a
  registration has issued no admission and no ticket joins its row.
- **`admissions.buyer_user_id`** — the registrant who paid for or submitted
  the registration this admission came from, threaded from
  `RegistrationConfirmed`/`RegistrationAmended` and backfilled for
  pre-existing rows by Registration's `registration:announce-ownership`.
  Distinct from `holder_user_id`: a registration-derived admission is born
  with a buyer and no holder, and stays that way until someone claims it.
- **`admissions.entry_conditions`** — what the door will have to ask this
  holder for (ADR-0066 §6.4, D-ADM-06): the list `RegistrationConfirmed`
  carries beside its grants, stamped as **opaque scalars** on every admission
  the confirmation issues — `key`, `label`, `verification`, `document_hint`
  and nothing else. Admission reads no Registration table and learns of no
  assertion: it carries four fields to the entrance payload and the ticket
  and never looks them up again, so a condition reworded tomorrow does not
  rewrite a ticket already in somebody's pocket. Null on every admission
  issued before the feature — there is no backfill, and the door then asks
  for nothing. The `document_at_door` ones also get an `entry_conditions`
  entry in the delta feed (`EntranceDeltaEntry::KIND_ENTRY_CONDITIONS`,
  payload `{conditions: [{key, label, document_hint}]}`), which is how an
  offline device knows before the queue arrives. That stays PII-free: a
  label beside an opaque admission ULID says what was bought, never who
  bought it. The condition's **name and document hint print on the ticket**
  ("Student ticket — bring a valid student ID"); the **badge carries none of
  it** (D-ADM-07), and the registrant's own assertion sentence reaches
  neither.
- **Check-in and check-out records** — every scan, accepted or rejected, with
  checkpoint, device, operator, and timestamp. This is the audit trail of who
  was in the building. `scanned_at` and `synced_at` are separate: an offline
  device buffers, so a scan can reach the server long after the person walked
  in, and the trail must say when they actually did. Since #329 a row also
  carries `actor_user_id` (the admin who checked the ticket in or out from the
  console, null for a door scan; exported and nulled by the GDPR handler) and
  `conditions_checked` (the `[{key, outcome}]` door-check answers of an
  accepted check-in, ADR-0066 §6.4), which is where the ticket page and
  `Support\OwedDoorChecks` read what a ticket has already been asked. Those
  outcomes are the guest's data, so the holder's GDPR export lists every
  check-in on their own tickets with them; the rows stay on erasure as the
  attendance record (#329 final review M3).
- **The console desk** (#329, attendee-list design §4) — the attendee list
  of an event (`/admin/events/{event}/attendees`), the ticket detail page
  (`/admin/tickets/{admission}`, `TicketDetailController`,
  `Queries\TicketDetailQuery`) and the console check-in and check-out
  (`POST /admin/tickets/{admission}/check-in|check-out`,
  `ConsoleCheckInController`, rate-limited by `admission-console-check-in`,
  60 a minute per account). Every write goes through
  `Actions\CheckInFromConsole` and the shared core `RecordCheckIn`, so the
  console cannot admit anyone the door would refuse; the operator only
  answers the door checks. Gated by `CheckInAttendees`, then scoped to the
  admin host's operator (404 otherwise).
- **`Checkpoint`** — a named door at an event. **Carries no policy**: whether a
  repeat scan is a conflict or legitimate re-entry is the event's decision
  (D-ADM-03), and a checkpoint only enforces it. The absence of a policy
  column is that decision made structural (ADR-0033). `kind` (`door` or
  `console`, `CheckpointKind`) is not a policy either: each event has one
  `console` checkpoint, created on the first console check-in, that the
  console writes against and no device is ever enrolled at; anything offering
  checkpoints to a device reads `Checkpoint::doors()` (#328).
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
  (ADR-0004 forbids reading Event's models). The calendar's dates are the
  event's local dates (ADR-0027), so `dayCovering()` reads a moment's date in
  the event's zone, taken from `EventSummary` (UTC until one is projected):
  00:30 in Berlin in summer belongs to the new local day although it is
  22:30 UTC the evening before. The door device and `PresenceWindow` read the
  same local date, so the core, the console, the attendee list, the device
  and the hub agree on the day (#329 final review I1).
- **`EventPresence`** (`admission_event_presence`) — one person's standing at
  one event (D-HUB-01/02): when they were first admitted, where they last
  scanned (`last_direction`), and whether the live hub is open to them
  (`hub_access`, derived but stored so no consumer does date math;
  `window_ends_at` null means "until the event concludes"). Written only by
  `Actions\ProjectEventPresence` off an accepted scan; closed by the event
  ending, the admission being revoked, or the once-per-day window passing
  (`admission:close-presence-windows`, every minute). Nobody outside
  Admission reads the table — Platform and Community learn of it through
  `ParticipantAdmitted` and its siblings. Exported and deleted (both modes)
  by the GDPR handler: a presence without a person means nothing. Counted
  on `admission_hub_access_total{brand, outcome}`.
  `announced_hub_access` and `announced_direction` are not state but the
  acknowledgement of it — the values the participant events have actually
  carried out of the module. The projection runs from a queued job and the
  handlers behind those events are not queued, so one of them throwing fails
  the job after the row was saved; deciding "did this transition?" from the
  saved row would let the retry swallow the event for good. The markers move
  only after the dispatch, so a retry re-emits exactly once. Null means
  nothing has been announced yet and is never defaulted to a value on
  insert — a row that reached `hub_access = false` with the marker still
  null is one two dispatches died on, and the sweeps keep picking it up
  until it is settled.
  `admission:replay-presence` re-emits `ParticipantAdmitted` and
  `ParticipantPresenceChanged` for every row that still has access (`--event`
  scopes it), and `--event=… --seed` rebuilds an event's rows from its
  accepted check-ins — the rollout step for a release that lands mid-event,
  when everybody already inside would otherwise have no hub
  (`docs/operations.md`).
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
| `AdmissionCheckedIn` | A scan is accepted at a checkpoint. **`registration_id` is load-bearing beyond this module**: ADR-0037 blocks a refund on a checked-in admission, and D-COM-15 settles that Commerce answers that from its own read model fed by this event — never by reading Admission's tables. Slice 7's refunds were blocked until this shipped. `conditions_checked` is what door staff answered to this holder's document checks (ADR-0066 §6.4): `[{key, outcome}]` with `outcome ∈ confirmed\|refused`, empty for every scan that checks nothing. Registration stamps its own assertion from it and **a refusal revokes nothing** (T-8, ADR-0005) | `check_in_id`, `admission_id`, `registration_id` (nullable — a crew badge has none, D-STF-01; widened additively by #333, Commerce's refund block skips a null), `event_id`, `brand_id`, `event_day_id` (nullable), `checkpoint_id` (nullable — the console checkpoint's id for a console check-in, attendee-list design §3.1), `direction`, `scanned_at`, `conditions_checked` | implemented |
| `ConsoleCheckInRecorded` | An operator checked a ticket in or out from the console (#329, attendee-list design §3.1) and the shared core wrote a row, accepted or rejected. **Auditable** (`admission.console-check-in` / `admission.console-check-out`, subject the admission, context `result`, `reason_code`, `check_in_id`); dispatched after commit. A console attempt refused before anything was written (already in, not in, unanswered or unknown door checks, a ticket of another event) raises nothing | `check_in_id`, `admission_id`, `event_id`, `brand_id`, `actor_user_id`, `direction`, `result`, `reason_code` (nullable) | implemented |
| `CheckInRejected` | A presented token is refused. A first-class event, not a swallowed error — the audit trail records why | `check_in_id`, `admission_id` (nullable — a bad signature never resolves to one), `event_id`, `brand_id`, `checkpoint_id` (nullable), `reason_code`, `scanned_at` | implemented |
| `CheckInBatchSynchronized` | A device delivered its buffered scans. `duplicates` counts scans already recorded — the normal shape of a reconnection replay, not a fault | `device_id`, `event_id`, `brand_id`, `accepted`, `rejected`, `duplicates`, `synchronized_at` | implemented |
| `AdmissionCheckedOut` (proposed) | A holder is scanned out. Recorded today as a `direction: out` check-in; a distinct event only if a consumer needs one | `admission_id`, `checkpoint_id`, `device_id`, `checked_out_at` |
| `ParticipantAdmitted` | A signed-in participant's hub access went false→true (D-HUB-01): their first accepted scan at the event, or the first scan of a new day on a `once_per_day` event. This is what opens `/live` for them — Platform projects it into its own read model and never reads `admission_event_presence` (ADR-0004). `window_ends_at` null means open until the event concludes; the display fields are nullable because an event may have no day calendar and the summary may not be projected yet | `user_id`, `event_id`, `brand_id`, `admission_id`, `admitted_at`, `window_ends_at` (nullable), `day_label` (nullable), `event_name` (nullable), `event_slug` (nullable) | implemented |
| `ParticipantPresenceChanged` | A participant's `last_direction` changed — scanned in, or checked out at the desk (D-HUB-02). "On site" is a badge, never a gate: this event does not touch hub access and consumers must not derive it from here. Community's Checked-In presence (D-HUB-03) reads this | `user_id`, `event_id`, `brand_id`, `direction` (`in`\|`out`), `changed_at` | implemented |
| `ParticipantHubAccessEnded` | A participant's hub access went true→false. `reason` is `HubAccessEndReason`'s backing string — `day_ended` (a once-per-day window closed; the next day's accepted scan re-admits them), `concluded`/`cancelled` (the event did), `revoked` (the admission that let them in was — unless another admission of theirs still stands, in which case the row moves over to it, access never lapses, and `ParticipantAdmissionReplaced` is emitted instead of this event) | `user_id`, `event_id`, `brand_id`, `reason`, `ended_at` | implemented |
| `ParticipantAdmissionReplaced` | The admission behind a participant's hub access was revoked and another admission of theirs at the same event still stood, so the presence row moved over to it. Access never lapsed — no `ParticipantHubAccessEnded` accompanies this, and **nobody may derive access from it**. It exists because consumers *mirror* `admission_id`: Platform's `platform_hub_access` carries it and Resources builds the hub's seat link out of it, so a silent re-point would leave that link naming the revoked admission. `admission_id` is the **surviving** one | `user_id`, `event_id`, `brand_id`, `admission_id`, `replaced_at` | implemented |

## Consumes

Events from other modules that this module listens to, and the read models it
maintains from them.

| Event | Owning module | Why |
|---|---|---|
| `BrandCreated` | Platform | A brand can issue tickets from its first minute (#287): `MintEntranceSigningKeyOnBrandCreated` mints the brand's Ed25519 entrance signing key (ADR-0033) if it has none that can still sign. Without one `IssueAdmissionToken` refuses to sign at all, so the first ticket download of every brand was a 500. Idempotent — a replay never retires a working key — and only half the fix: brands built without going through `Platform\Actions\CreateBrand` are covered by `admission:rotate-signing-key --all` on the deploy path |
| `RegistrationConfirmed` (proposed) | Registration | The trigger: issue one admission per granted admission in the payload, with the granted role — and, since ADR-0066, stamp `entry_condition_keys` on each of them as opaque scalars (D-ADM-06) |
| `RegistrationAmended` | Registration | Grants were added to an already-confirmed registration (ADR-0036): issue **exactly the delta**. Idempotency is keyed on `amendment_id` (null for the original confirmation) rather than "does this registration have any admissions?", which would swallow every amendment |
| `EventDaysChanged` | Event | Projects the event-day calendar into `EntranceEventProfile` so a door can resolve the current day locally (D-ADM-04, ADR-0033). Replaces rather than patches — the payload always carries the complete set |
| `EventReentryPolicyChanged` | Event | Projects the re-entry knob into the same read model (D-ADM-03). Without it the door could not evaluate re-entry offline, and Admission may not read Event's models (ADR-0004) |
| `RegistrationCancelled` (ADR-0057 point 6) | Registration | Revoke the admissions that registration produced — `AdmissionState::Revoked`, one `AdmissionRevoked` each, and a `KIND_REVOKED` entry in the entrance delta feed so offline doors (ADR-0033) stop admitting them. An admission with an accepted check-in is kept: attendance happened, and money owed after the fact is Commerce's receivable, not the door's. This is the only path by which a cancellation reaches Admission; there is deliberately no listener on `PaymentReversed`, because a door that evaluates payment is ADR-0005's prohibition |
| `RegistrationQuantityReduced` (D-REG-15) | Registration | The act got **smaller**, so exactly `removed_grants`' admission units end. `RevokeUnitsForRegistration` picks them cheapest-first — unassigned, unclaimed and unseated before unassigned-with-a-live-claim-link, whose link is revoked with the admission — and never an admission with a holder, a row in `admission_seats` or an accepted check-in. Fewer free than asked for is a **refusal**: `NotEnoughFreeAdmissionsException` naming how many there are, thrown from a synchronous listener inside Registration's own transaction, which rolls the whole reduction back and stops Commerce's queued refund from ever being pushed. Idempotent per `admissions.reduction_id`, the subtractive twin of `amendment_id`; reason `registration_quantity_reduced`, with N × `AdmissionRevoked` and N `KIND_REVOKED` delta rows |
| `UserRegistered`, `UserProfileUpdated` | Identity | Maintain `Models\Person`, the holder-name read model the ticket and the badge fallback both read (ADR-0061). Backfilled by `identity:replay-profiles` |
| `EventCreated`, `EventRescheduled`, `EventDetailsUpdated`, `VenueAssigned`, `VenueUpdated`, `VenueUnassigned` | Event | Maintain `Models\EventSummary` (`admission_event_summaries`): times and timezone from the first two, venue label from the venue events, name/slug patched (not replaced) from `EventDetailsUpdated`. Backfilled by `event:replay-details`, which since this feature also re-fires `VenueAssigned` for each event's primary venue |
| `RegistrationConfirmed`, `RegistrationCancelled`, `RegistrationStandingAnnounced` | Registration | `ProjectRegistrationSummary` (#329): maintain `Models\RegistrationSummary` (`admission_registration_summaries`), the attendee list's ticket type and the registration's status, from `RegistrationConfirmed.registration_type_name` and the standing announcement's trailing `registration_type_id`, `registration_type_name` and `status`. Backfilled by `registration:announce-standing`; only a projection listens to that event |
| `RegistrationOwnershipAnnounced` | Registration | Backfills `admissions.buyer_user_id` for rows that predate the column — the replay-safe counterpart to `RegistrationConfirmed`/`RegistrationAmended`, which already carry `registrantUserId` going forward |
| `UserAnonymizationRequested` (proposed) | Compliance | Hand the request to this module's `src/Gdpr/` handler; check-in records are attendance data and need an explicit answer |
| `ResourceBookingConfirmed`, `ResourceBookingReleased` | Resources | `ProjectAdmissionSeat` (#292 §5): maintain `Models\AdmissionSeat` (`admission_seats`) — the place bound to an admission, for the ticket page and the ticket PDF — from a *confirmed* booking that names an `admissionId`, deleted again when that booking is released. A hold never reaches a ticket; a booking naming no admission is nobody's seat here |
| `EventConcluded`, `EventCancelled` | Event | `EndHubAccessOnEventClosed` (D-HUB-01, design §4.2): a closed event has nobody inside it any more, so every `EventPresence` row of it loses `hub_access` — `concluded` or `cancelled` by which door it left through — and one `ParticipantHubAccessEnded` fires per row. Queued after commit, like the presence projection: both events fire inside the admin's transaction and a big event's sweep is thousands of rows |

## Capabilities

Declared in `src/Enums/Permission.php`. Roles that grant them are per-brand
data, not code (ADR-0013).

The catalog covers: viewing admissions, issuing and revoking them, assigning or
reassigning holders, performing check-in and check-out, printing and
reprinting badges, managing checkpoints and entrance devices, and reading the
check-in log. Door staff need a narrow set — scan and print — and nothing else;
that separation is the reason the check-in capability is distinct from the
management ones.

`CheckInAttendees` (`admission.check-in-attendees`, #329) is the console
desk: the attendee list, the ticket page and checking a ticket in or out by
hand. It is apart from `ScanCheckIn` because the door never sees a name
(ADR-0033) and the desk does. The **Einlass** default role that would grant
only it (D-ATT-06) is deferred to #334: console capabilities resolve against
the operator's team (ADR-0029) and a module can declare default roles at
brand scope only (ADR-0045), so a brand-scoped Einlass would open no page.
Until then Operator Admin and Brand Admin hold it through their full grants;
only Operator Admin's, on the operator's scope, opens the console.

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

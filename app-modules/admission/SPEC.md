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

- **`Admission`** — a single person's access right: holder, admission role
  (`visitor`, `exhibitor_crew`, `speaker`, `press`, `crew`, …), validity window,
  and lifecycle state (issued, assigned, revoked, expired). References its
  originating registration by ULID only.
- **Holder assignment** — an admission may be issued unassigned (group and B2B
  bookings) and be claimed or named later.
- **Ticket tokens** — the scannable credential, its format, its issuance, and
  its rotation or invalidation.
- **Badge** — the physical or printed identity of the holder at the venue:
  what is on it, when it is issued, and when it is reprinted.
- **Check-in and check-out records** — every scan, accepted or rejected, with
  checkpoint, device, operator, and timestamp. This is the audit trail of who
  was in the building.
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
| `AdmissionIssued` (proposed) | An admission is created from a confirmed registration's grants | `admission_id`, `registration_id`, `event_id`, `brand_id`, `role`, `holder_user_id` (nullable), `valid_from`, `valid_until`, `issued_at` |
| `AdmissionHolderAssigned` (proposed) | An initially unassigned admission gets a named holder | `admission_id`, `holder_user_id`, `assigned_at` |
| `AdmissionReassigned` (proposed) | An admission is transferred to a different person | `admission_id`, `previous_holder_user_id`, `holder_user_id`, `reassigned_at` |
| `AdmissionRevoked` (proposed) | An admission stops being valid before its window ends | `admission_id`, `revoked_by_user_id`, `reason_code`, `revoked_at` |
| `TicketTokenIssued` (proposed) | A scannable token is minted or rotated | `admission_id`, `token_id`, `issued_at`, `supersedes_token_id` (nullable) |
| `BadgeIssued` (proposed) | A badge is produced or reprinted at the venue | `admission_id`, `badge_id`, `issued_by_user_id`, `issued_at`, `reprint` |
| `AdmissionCheckedIn` (proposed) | A scan is accepted at a checkpoint | `admission_id`, `checkpoint_id`, `device_id`, `operator_user_id`, `checked_in_at`, `source` (`online` / `offline`) |
| `AdmissionCheckedOut` (proposed) | A holder is scanned out | `admission_id`, `checkpoint_id`, `device_id`, `checked_out_at` |
| `CheckInRejected` (proposed) | A presented token is refused | `admission_id` (nullable), `token_id` (nullable), `checkpoint_id`, `device_id`, `reason_code`, `rejected_at` |
| `CheckInBatchSynchronized` (proposed) | An offline device reconciles its buffered scans | `device_id`, `checkpoint_id`, `accepted_count`, `conflict_count`, `synchronized_at` |

## Consumes

Events from other modules that this module listens to, and the read models it
maintains from them.

| Event | Owning module | Why |
|---|---|---|
| `RegistrationConfirmed` (proposed) | Registration | The trigger: issue one admission per granted admission in the payload, with the granted role |
| `RegistrationCancelled` (proposed) | Registration | Revoke the admissions that registration produced |
| `UserProfileUpdated` (proposed) | Identity | Maintain the holder read model (badge name, photo reference) |
| `UserAnonymizationRequested` (proposed) | Compliance | Hand the request to this module's `src/Gdpr/` handler; check-in records are attendance data and need an explicit answer |
| `EventPublished`, `EventUpdated` (proposed) | Event | Maintain the event read model that supplies default validity windows and checkpoint context |
| `ResourceBookingConfirmed` (proposed) | Resources | Show the holder's seat or booth on the badge without owning the booking. Deliberately the *confirmed* event, not a hold — a tentative booking must never reach a badge |

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
   by ULID. **Still open:** where the badge *layout* itself lives — Admission
   templates, Platform's block/theming layer, or a print service.
7. ~~**Unassigned admissions.** Whether the placeholder for a not-yet-named
   group attendee is created here on confirmation or held in Registration
   until a person exists (paired with the same question in Registration's
   SPEC).~~ **Settled by ADR-0032 (decision log Round 6, D-COM-09):** Admission
   holds holder-less placeholders, issued on confirmation (blesses the Phase 1
   implementation); names attach later via claim links.

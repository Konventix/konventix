# Attendee list and console check-in — design

Date: 2026-09-23. Status: approved in conversation with the operator (2026-09-23), awaiting spec review.
Release: 0.9.1. Issue: #329. Related: #328 (the door app as a camera PWA — deferred, out of scope here),
#330 (claimed tickets may enter competitions), ADR-0033 (entrance offline trust model), ADR-0066 (door checks),
ADR-0004 (events are the only coupling), ADR-0029 (operator console), ADR-0001 (console copy is English).

## 1. Problem

Everything 0.9.0 added for guests on site — the `/live` hub and its cards, the shoutbox, competitions that require
an event check-in — opens only after a check-in at the door. The only thing that records a check-in is the door
device (`/entrance`, `RecordScan` via `SynchronizeScanBatch`), which accepts a scanned or pasted ticket token and,
by ADR-0033, never knows a name. So a guest without a working QR (dead phone, no printout) cannot be admitted, and
nobody in the console can see who is on site. There is also no admin page for a single ticket.

## 2. Decisions (operator, 2026-09-23)

| # | Decision |
|---|---|
| D-ATT-01 | One row per **ticket** (admission). A person with two tickets appears twice; an unassigned ticket is a row with its buyer. |
| D-ATT-02 | One search field matches every listed field (§4.2). |
| D-ATT-03 | Each row has an action menu: **Check in**, **Check out**, **Show ticket**. |
| D-ATT-04 | Outstanding door checks (ADR-0066) are answered in the check-in dialog — confirmed or refused per check, exactly as at the door, recorded the same way. No silent override, no hard block. |
| D-ATT-05 | The username links to the existing user page `/admin/users/{user}/edit`; the ticket links to a NEW ticket detail page that also shows the registration's details. |
| D-ATT-06 | A new capability `CheckInAttendees` (list + check-in/out) and a new default role **Einlass** that grants only it; event administrators get it too. *(Einlass deferred to #334, 2026-09-23 — see §5.)* |
| D-ATT-07 | Admission owns the list, the detail page and the console check-in. |
| D-ATT-08 | The console and the door write a check-in through ONE shared core, so presence, the hub, the shoutbox badge, competition door presence and every other consumer react identically. |

## 3. Architecture

Admission owns everything here. It already holds the tickets (`admissions`: `holder_user_id`, `buyer_user_id`,
`registration_id`, `state`, `entry_conditions`, validity), the people (`admission_people`: `display_name`,
`username`, `email`, projected from Identity's events), the event summaries, checkpoints and check-ins. Nothing new
is read from another module's tables (ADR-0004).

Two things the list needs are not in Admission today:

- **A ticket type label.** Admission stores no registration type name. Admission projects it from Registration's
  events (the event that issues admissions or confirms a registration, whichever carries the type; if none does,
  Registration adds the type name to the narrowest such event, additively). Stored on the admission row or in a small
  per-registration projection — the plan decides by what the events carry.
- **A short ticket reference.** The ULID is too long to read out at a desk. Defined as the last 8 characters of the
  admission ULID, upper-case, grouped `XXXX-XXXX`. The holder's ticket page and the ticket PDF show the same string,
  so a guest can read it out; add it there if absent. Searchable as typed with or without the hyphen.

### 3.1 The shared check-in core

`RecordScan::handle()` today verifies a signed token, then resolves the admission, then runs the admission checks
(event, day, validity, re-entry policy, door conditions) and writes an accepted or rejected `CheckIn`, emitting
`AdmissionCheckedIn` / `CheckInRejected`. Split it:

- `RecordScan` keeps the token verification (signature, expiry, nonce, event of the token) and hands the resolved
  admission to the core.
- A new `RecordCheckIn` (the core) takes an `Admission`, the event, a client scan id, the instant, a `Checkpoint`,
  an optional `EntranceDevice`, the direction, the answered conditions and an optional **acting user id**; it runs
  every admission-level check and writes the `CheckIn` exactly as today. Idempotency per `client_scan_id` stays.
- `CheckInFromConsole` (new) resolves the event's **console checkpoint** (created on first use, one per event,
  named "Console", flagged so it is never offered to a door device), generates a client scan id, and calls the core
  with the acting admin's id. It never touches tokens.

`check_ins` gains a nullable `actor_user_id` (who checked in from the console; null for a door scan). The audit log
records every console check-in/out with the actor (AuditableEvent, as other console actions do). `AdmissionCheckedIn`
keeps its payload; if a consumer needs to know it came from the console, it can read the checkpoint.

Check-out: direction `out` is already supported by the core; the console offers it for a ticket whose last accepted
check-in is `in`. Re-entry rules (D-ADM-03) apply to a console check-in exactly as to a scan — the console cannot
admit someone the door would refuse, except that the operator answers the door checks.

### 3.2 ADR-0033 amendment

ADR-0033 describes the door as offline, PII-free and token-driven. The console path is online, name-aware and
capability-gated, and it writes through the same core. Add an "Amended 2026-09-23 by the attendee-list design"
blockquote (ADR-0012's convention) stating that a second, online path exists for the console, that it never
weakens the door's model (the door still sees no names), and that both paths share one set of admission checks.

## 4. Console surfaces

### 4.1 Attendee list — `/admin/events/{event}/attendees`

A tab on the event dashboard ("Attendees"), beside the programme and shoutbox links. English copy (ADR-0001).

Columns: holder (display name + `@username`, or "Unassigned" with the buyer's name), email, ticket type, ticket
reference, registration (short id, links to the existing registration page), state (issued / assigned / revoked),
check-in (in / out / never, with time and "door" or "console"). Paginated, newest check-in activity first by default,
sortable by name.

Filters: checked in, not checked in, unassigned, revoked.

### 4.2 Search

One field, case-insensitive, partial match, across: holder display name, username, email; buyer display name,
username, email; ticket reference (with or without hyphen) and full admission id; registration id; ticket type
label. Postgres `ILIKE`, no trigram index unless the plan measures a need — the list is per event
(hundreds to low thousands of rows).

### 4.3 Row actions

- **Check in** — opens a dialog. If the ticket owes door checks (`entry_conditions` not yet answered for this event
  day), each is listed with Confirm / Refuse, as on the door screen; the check-in is submitted with those answers.
  Refusal behaves exactly as at the door (it records the fact; ADR-0066 decides the outcome). Disabled for revoked
  tickets and when the ticket is already in.
- **Check out** — confirm dialog; enabled when the last accepted check-in is `in`.
- **Show ticket** — the detail page.

Every refusal (e.g. not valid on this day, re-entry not permitted) is shown in the dialog as a sentence, never a 500.

### 4.4 Ticket detail — `/admin/tickets/{admission}`

Ticket reference, state, validity, holder and buyer (each linking to the user page), ticket type; the door checks
with their answers; the full check-in history (time, direction, result, reason, checkpoint, device or acting admin);
the registration's details as Admission knows them (type, status, registration id with a link to
`/admin/registrations/{id}`); the badge link that already exists; the same Check in / Check out actions.

## 5. Access and gating

- Capability `CheckInAttendees` in Admission's Permission enum; `permissions:sync` picks it up.
- Default role **Einlass** grants only `CheckInAttendees`; the event-administration default role also grants it.
  *Deferred 2026-09-23: the Einlass role part of D-ATT-06 moves to #334 (operator-scope console roles); 0.9.1 ships the capability only, held by Operator Admin and Brand Admin through their full grants.*
- Every route: capability, then operator scoping (the event's brand belongs to the admin host's operator — the
  `ScopesToOperator` pattern from Competition), 404 otherwise; the module gate as the admission console already does.
- Rate limit on the two write routes (a named limiter, as competition's participant writes).

## 6. Data and GDPR

- `check_ins.actor_user_id` (nullable ULID) — Admission's GDPR handler nulls it on erasure of that admin and exports
  it with the admin's data.
- The console checkpoint is a normal checkpoint row with a flag (`kind = console`), excluded from any device
  enrolment surface (#328).
- `check_ins.conditions_checked` (Task 1) stores the holder's per-condition door-check outcome (for example
  `student: refused`) on an accepted check-in, written by the door and the console alike. That is new person data about
  the guest: Admission's GDPR handler exports every check-in on the holder's own tickets with it. The rows are kept on
  erasure as the attendance record and then point at Identity's tombstone only; Registration's `EntryConditionAssertion`
  holds the same outcome and anonymises it (#329 final review M3). The list itself stores nothing new and reads
  `admission_people`.

## 7. Testing

- Search: one test per searched field, plus hyphen/no-hyphen reference and a non-match.
- Console check-in writes an accepted `CheckIn` with `actor_user_id`, emits `AdmissionCheckedIn`, and the holder's
  hub opens (presence projected) — the same assertions the door-scan tests make.
- Door checks: confirmed → accepted; refused → recorded as at the door.
- Revoked ticket → refused with a sentence; already in → check-in disabled; check-out → presence out.
- Re-entry policy applies to the console as to the door.
- Another operator's event → 404; without the capability → 403; module off → 404.
- `RecordScan` refactor: every existing door-scan test stays green unchanged (the split must not change door
  behaviour).
- Links: username → user page; ticket → detail page; registration → registration page.

## 8. Not in this design

The door app as a camera PWA, device enrolment and revocation (#328); bulk check-in; CSV export; printing badges
from the list (the badge link exists); showing people who hold no ticket.

# Staffing — Specification

> Stable document. Describes what this module *is*. Task tracking lives in
> `TODO.md`.

## Purpose

Staffing plans and tracks the people who *work* an event rather than attend it:
crew, volunteers, helpers, referees. It owns teams, shifts, shift roles, and
assignments, plus a `StafferProfile` read model of the people involved. It
replaces LanCore's OrgaTeam domain and generalizes it away from LAN-party crew
lists: a team is any named unit of the workforce, a shift is any time-bounded
piece of work. This is the headline differentiator against pretix and
Eventbrite — a brand that runs its event on Konventix plans its crew in the same
system that holds its registrations, instead of in a spreadsheet next to it. It
is also the first thing a pretix user notices here and cannot get there.

## Owns

- **Team** — a named unit of the workforce for an event (entrance, tech,
  catering, tournament desk), with its leads and its member pool.
- **ShiftRole** — the function a person fills during a shift, together with the
  qualification it requires and the headcount it demands.
- **Shift** — a time-bounded piece of work belonging to a team: start and end,
  where it happens, and how many people it needs per shift role.
- **Assignment** — one person, referenced by Identity user ULID, placed in one
  shift under one shift role, plus that assignment's state (offered, accepted,
  declined, released, completed, no-show).
- **StafferProfile** — the module's read model of a person as a staffer:
  display data mirrored from Identity events (ADR-0004) alongside
  staffing-local attributes such as availability windows, qualifications, and
  contact preference for roster changes. Staffing never reads Identity's `User`
  model; it holds ULIDs and its own copies.
- **Coverage** — the derived answer to "is this shift staffed?", computed from
  demand per shift role against accepted assignments. It is a query, not a
  stored status.
- Self-service sign-up rules: which shifts a staffer may claim without a lead's
  approval, and the approval step when they may not.

## Does not own

| Nearby concept | Owned by |
|---|---|
| User accounts, names, e-mail addresses, authentication | **Identity**. Staffing keeps `StafferProfile` as a read model and references people by ULID. |
| A staffer's right to be on site, their badge, their check-in at the entrance | **Admission**. A crew person still holds an Admission; being rostered is not an access right. |
| The commercial act that produced a crew admission (the `crew` registration type: invite_only / none / free / on_approval, ADR-0005) | **Registration** |
| The room, LAN seat, or piece of equipment a shift takes place at or needs | **Resources**. A shift references a resource by ULID; it does not model the resource. |
| The event itself, its dates, and its venue | **Event** |
| Public programme items and their timetable | **Agenda**. A shift is internal work, a programme item is public — they stay different entities even when they overlap in time. |
| Delivery of shift reminders and roster-change notices | **Messaging**. Staffing states that something happened; Messaging decides channel and delivery. |
| Expense reimbursement, allowances, any movement of money | **Commerce** — and no funds ever flow through the platform (ADR-0007). |
| Matches, referee results, tournament schedule | **Competition**. Staffing may roster a referee onto a shift; the match belongs to Competition. |
| Which roles exist per brand and which capabilities they grant | **Platform** (spatie/laravel-permission rows, per-brand data). Staffing only declares its capability enum (ADR-0013). |
| Cross-event staffer reputation, badges, achievements | **Community** (post-release) |

## Public contract (events)

Events are the only way other modules may react to this one (ADR-0004).
Payloads carry ULIDs and scalars — never Eloquent models.

All rows below are **proposed**: no ADR names Staffing's events yet, so this is
the intended contract and may still change while the module is built.

| Event | Emitted when | Payload |
|---|---|---|
| `ShiftPublished` *(proposed)* | A shift becomes visible to staffers and open for sign-up | `shift_id`, `event_id`, `team_id`, `starts_at`, `ends_at` |
| `ShiftChanged` *(proposed)* | A published shift's time, place, or demand changes | `shift_id`, `event_id`, changed field names, new `starts_at`, `ends_at` |
| `ShiftCancelled` *(proposed)* | A published shift is withdrawn | `shift_id`, `event_id`, `cancelled_at` |
| `AssignmentOffered` *(proposed)* | A lead offers a shift slot to a person | `assignment_id`, `shift_id`, `shift_role_id`, `user_id`, `offered_at` |
| `AssignmentAccepted` *(proposed)* | A person confirms they will work the shift | `assignment_id`, `shift_id`, `shift_role_id`, `user_id`, `accepted_at` |
| `AssignmentReleased` *(proposed)* | An accepted assignment is given back or withdrawn | `assignment_id`, `shift_id`, `user_id`, `released_at`, `reason` |
| `ShiftUnderstaffed` *(proposed)* | Coverage for a shift role drops below demand inside the alert window | `shift_id`, `shift_role_id`, `required_headcount`, `accepted_headcount` |

## Consumes

Events from other modules that this module listens to, and the read models it
maintains from them.

| Event | Owning module | Why |
|---|---|---|
| `UserRegistered` *(proposed)* | Identity | Creates the `StafferProfile` row keyed by the user ULID. |
| `UserProfileUpdated` *(proposed)* | Identity | Keeps mirrored display data on `StafferProfile` current. The projection must be idempotent and replayable (ADR-0004). |
| `AdmissionIssued` / `AdmissionHolderAssigned` *(proposed)* | Admission | An admission carrying a crew role identifies a person eligible for assignment. Registration's `RegistrationConfirmed` does not name the people it grants admissions to, so eligibility is read from Admission, not from Registration. |
| `AdmissionRevoked` *(proposed)* | Admission | Someone who lost their access right cannot work the event; their open assignments are flagged for the team lead. |
| `EventArchived` *(proposed)* | Event | Closes the roster; shifts become read-only history. |
| `DataExportRequested` / `UserDeletionRequested` *(proposed)* | Compliance | Fans out to `src/Gdpr/`: exports or anonymizes `StafferProfile` and assignment history, and reports completion back to Compliance. |

Staffing deliberately does not listen to Commerce. Payment state reaches it, if
at all, through Registration (ADR-0006).

## Capabilities

Declared in `src/Enums/Permission.php`. Roles that grant them are per-brand
data, not code (ADR-0013).

- Manage teams and their leads.
- Manage shift roles and their qualification requirements.
- Create, publish, change, and cancel shifts.
- Offer, confirm, and release assignments for other people.
- Claim and release one's own assignments — the self-service capability every
  staffer role gets.
- View the full roster of an event, including other staffers' contact data.

## Activation

Pennant feature name: **`staffing`** (the module's kebab-case name), gated per
brand at all four enforcement points (ADR-0003): the route group carries
`ensure-module:staffing`, Platform's nav registry filters the module's entries,
UI slots check the feature, and every listener short-circuits when the feature
is off for the event's brand context.

With the feature off a brand has no crew planning at all: no teams, no shift
plan, no volunteer sign-up, and no staffer read model, because the Identity
projections short-circuit too. Registration, Admission, and Commerce are
unaffected — crew people can still be registered and admitted, they simply
cannot be rostered. Enabling the feature later therefore needs a backfill of
`StafferProfile` from existing Identity data rather than a replay of missed
events.

## Open questions

- Do teams nest (a "Tech" team with "Network" and "Stage" below it), or is a
  flat list per event with tags enough for our own events?
- ~~Is self-service sign-up the default with an opt-in approval step per team,
  or the reverse? This decides whether `AssignmentOffered` or
  `AssignmentAccepted` is the usual entry point into the assignment state
  machine.~~ **Decision log Round 6 (D-STF-01):** self-service sign-up is the
  default; per-team approval is opt-in. `AssignmentAccepted` is the usual
  entry point — a team without approval turned on never needs
  `AssignmentOffered` at all.
- ~~Should accepting an assignment automatically produce a crew Admission, or
  must a crew Registration always exist first? The second keeps ADR-0005's
  boundary clean but adds a manual step for small teams.~~ **Decision log
  Round 6 (D-STF-01):** an accepted assignment auto-creates the crew
  Admission via an event contract — Staffing emits `AssignmentAccepted`,
  Admission listens and issues the admission. ADR-0005's boundary stays
  clean because Staffing never issues the admission itself.
- How are qualifications modelled? They must be typed value objects or their own
  rows — free-form JSON attribute blobs are forbidden (ADR-0009).
- Do availability and qualifications carry across events of the same brand, or
  across brands? Cross-event participant identity is Community territory
  (post-release), so Staffing must not quietly become the cross-event store.
- What is exported for a staffer under GDPR: assignment history alone, or also
  notes written by leads? The answer decides whether such notes are a
  first-class entity or must not exist at all.

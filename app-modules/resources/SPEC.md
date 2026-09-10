# Resources — Specification

> Stable document. Describes what this module *is*. Task tracking lives in
> `TODO.md`.

## Purpose

Resources owns everything an event has a limited amount of and that somebody can
hold for a period of time: seats, rooms, booth space, loanable devices. It
generalizes LanCore's Seating domain, and the generalization is the point — a
seat is **one resource type with a time dimension**, not a special case with a
booking layer bolted on. Every resource type goes through the same entity, the
same availability model, and the same booking state machine; rooms, devices, and
booth space are further types of the same thing, not further subsystems. A
booking always occupies an interval, even when that interval happens to be "the
whole event", which is how LanCore's implicit seat model reappears here without
its assumptions.

## Owns

- **ResourceType** — what kind of thing is bookable (seat, room, booth space,
  device), the typed attributes its resources carry, and the booking rules that
  apply to it: minimum and maximum interval, whether bookings may overlap up to
  a capacity, whether holders may transfer.
- **Resource** — one bookable unit, belonging to an event and referencing a
  venue by ULID: its label, its typed attributes, and its position when it is
  part of a plan that gets rendered.
- **Availability** — the intervals during which a resource may be booked at all,
  including blackout periods for maintenance, setup, and teardown. This is the
  time dimension, and it exists for every resource type.
- **ResourceBooking** — a claim on a resource for a half-open interval by a
  holder referenced by ULID (a registration, an admission, a shift, an
  organizer), plus its state: held, confirmed, released, cancelled, expired.
- **Hold expiry** — an unconfirmed booking is a temporary hold with a deadline,
  released automatically when it passes.
- **Conflict prevention** — the guarantee that two bookings never overlap on the
  same resource beyond its capacity. This is the module's core invariant and it
  is enforced in the persistence layer, not in a controller.
- **Claim fulfilment** — turning a `grants: resource claims` entitlement from a
  RegistrationType (ADR-0005) into concrete bookings, and releasing them again
  when the entitlement goes away.

## Does not own

| Nearby concept | Owned by |
|---|---|
| What was bought, by whom, and under which registration type — including the resource *claim* that entitles a booking | **Registration** (ADR-0005). Resources fulfils claims; it does not grant them. |
| Prices, orders, invoices, refunds for a booked resource | **Commerce** (ADR-0006). Resources never prices anything and never emits money-shaped events. |
| A person's right to be on site, badge, check-in | **Admission**. A booking is not an access right; a seat can be booked by a registration whose admissions are still unissued. |
| The event and the venue as lifecycle entities | **Event** (venue is a sub-concept there). Resources references `venue_id` as a plain ULID and models only the bookable units inside it. |
| Who works when and where | **Staffing**. A shift may reference a resource by ULID; Resources does not know what a shift is. |
| Game servers and match sessions | **GameOps**. A Deployment is ephemeral, provider-managed infrastructure with its own lifecycle (ADR-0012) — it is not a bookable Resource and must never be modelled as one. |
| Matches, brackets, tournament schedules that need a room | **Competition** |
| Public brand pages that embed a seat plan | **Platform** / **Content**. Resources registers a block type for the block-based CMS; the page and its publishing belong to the content side. |
| Notifying a holder that their booking expired | **Messaging** |
| Which roles may book on someone else's behalf | **Platform** (per-brand role data, ADR-0013) |

## Public contract (events)

Events are the only way other modules may react to this one (ADR-0004).
Payloads carry ULIDs and scalars — never Eloquent models.

The three booking events are shipped (payload keys are the constructor's
camelCase properties); `ResourcePublished`, `ResourceWithdrawn` and
`ResourceBookingTransferred` remain **proposed**.

| Event | Emitted when | Payload |
|---|---|---|
| `ResourcePublished` *(proposed)* | A resource becomes bookable for an event | `resource_id`, `resource_type_id`, `event_id`, `venue_id`, `label` |
| `ResourceWithdrawn` *(proposed)* | A resource stops being bookable; existing bookings need handling | `resource_id`, `event_id`, `withdrawn_at`, `reason` |
| `ResourceBookingHeld` | A temporary hold is placed | `bookingId`, `resourceId`, `brandId`, `holderType`, `holderId`, `quantity`, `startsAt`, `endsAt`, `holdExpiresAt` (null for an unclocked hold), `eventId`, `resourceTypeId`, `registrationId`, `claimId`, `label`, `ref` |
| `ResourceBookingConfirmed` | A hold becomes a firm booking — **the event other modules act on** | `bookingId`, `resourceId`, `brandId`, `holderType`, `holderId`, `registrationId`, `quantity`, `startsAt`, `endsAt`, `confirmedAt`, `eventId`, `resourceTypeId`, `claimId`, `label`, `ref` |
| `ResourceBookingReleased` | A booking is given back, cancelled, or expires | `bookingId`, `resourceId`, `brandId`, `holderType`, `holderId`, `reason` (`registration_cancelled`, `registration_rejected`, `plan_conflict`, `repicked`, `operator_released`, `expired`, or a caller's own), `releasedAt`, `eventId`, `resourceTypeId`, `registrationId`, `claimId`, `label`, `ref` |
| `ResourceBookingTransferred` *(proposed)* | A booking moves to a different holder or a different resource | `booking_id`, `resource_id`, `previous_resource_id`, `holder_id`, `previous_holder_id`, `transferred_at` |

Admission's SPEC currently refers to the badge-relevant event as
`ResourceBookingCreated`. The name to settle on is `ResourceBookingConfirmed`: a
hold is provisional and must never reach a badge, so the two modules should not
carry a third name for the same moment.

## Consumes

Events from other modules that this module listens to, and the read models it
maintains from them.

| Event | Owning module | Why |
|---|---|---|
| `RegistrationConfirmed` *(proposed)* | Registration | The one event that materializes entitlements: its `grants` list carries the claimed `resource_type_id` and quantity, which this module turns into holds or confirmed bookings depending on the type's rules. Must be idempotent — the same event may be replayed. |
| `RegistrationCancelled` *(proposed)* | Registration | Releases every booking held under that registration. |
| `EventCreated`, `EventRescheduled`, `EventDetailsUpdated` | Event | Projected into `resource_event_windows` (`ProjectEventWindow`): the UTC instants of the event's wall-clock times in the payload's zone (ADR-0027), its name and slug. Upsert on `(brand_id, event_id)`; ungated so a brand that switches Resources on later finds the window there. `event:replay-details` backfills events older than the projection. |
| `AdmissionRevoked` *(proposed)* | Admission | Releases bookings whose holder is that admission (a personal seat), leaving registration-level bookings alone. |
| `EventArchived` *(proposed)* | Event | Closes the booking window; bookings become read-only history. |
| `DataExportRequested` / `UserDeletionRequested` *(proposed)* | Compliance | Fans out to `src/Gdpr/`: exports a person's booking history, detaches holder references, and reports completion back to Compliance. |

Resources does **not** listen to Commerce. Payment never reaches this module
directly; Registration consumes `OrderPaid` and confirms, and Resources reacts
to Registration only (ADR-0006). It keeps no read model of people — a booking
holder is a ULID plus a holder type, and any display name is resolved by the
module that owns it.

## Capabilities

Declared in `src/Enums/Permission.php`. Roles that grant them are per-brand
data, not code (ADR-0013).

- Manage resource types and their booking rules.
- Create, publish, and withdraw resources; edit plans and positions.
- Manage availability windows and blackout periods.
- Book and release resources on behalf of another holder.
- Book and release one's own resources — the participant-facing capability.
- View the full booking list of an event, including holder identity.

## Activation

Pennant feature name: **`resources`** (the module's kebab-case name), gated per
brand at all four enforcement points (ADR-0003): the route group carries
`ensure-module:resources`, Platform's nav registry filters the module's entries,
UI slots (including the seat-plan block) check the feature, and listeners
short-circuit when the feature is off for the event's brand context.

With the feature off a brand sells registrations but allocates nothing: no seat
plan, no room booking, no booth assignment, no device loans. A RegistrationType
that grants resource claims still registers people correctly — the claims simply
stay unfulfilled rather than failing the registration, so switching the feature
on later can fulfil them retroactively instead of stranding the registration.

## Open questions

- ~~Is a resource with capacity greater than one (a room for 40, a booth strip
  sold in square metres) one row with a capacity column, or many rows of one
  unit each? ADR-0005's exhibitor example books "booth B12, 12 m²", which
  points at quantity rather than unit — but a capacity column complicates the
  overlap invariant.~~ **Settled by ADR-0034 (decision log Round 6,
  D-EVT-03):** per resource type — `unit` mode (row per unit, exclusion
  constraint applies directly) or `quantity` mode (capacity column, summed
  overlap check).
- ~~How is the no-overlap invariant enforced?~~ **Settled by ADR-0020** (an
  exclusion constraint over a range type), and its precondition is met: the
  test suite now runs against PostgreSQL, not SQLite.
- ~~Waitlists and overbooking: does an exhausted resource type queue holders,
  and if so does the queue belong here or to Registration's quota model?~~
  **Decision log Round 6 (D-REG-04, user override):** the waitlist belongs to
  **Registration** — quota exhaustion produces a waitlist entry there.
  Resources has no queue of its own and points at Registration's.
- ~~Interval granularity: minutes, or slots defined per resource type?
  Whole-event bookings must stay a degenerate case of the same model, never a
  separate path.~~ **Decision log Round 7 (D-BATCH-7):** minute-precision
  half-open timestamp ranges (ADR-0020); whole-event booking is the
  degenerate case, never a separate path.
- ~~Who may transfer a booking to another holder, and does a transfer need
  Registration's consent when the booking came from a claim? **Still open** —
  follows Registration's paid-flow amendment/transfer decision (decision log
  Round 6, D-BATCH: "amendment/transfer decided during the paid-flow build"),
  since a resource-booking transfer is downstream of whether the registration
  itself may be amended or transferred.~~ **Settled by ADR-0036 (decision log
  Round 7):** a resource-booking transfer follows the registration's
  amendment/transfer rules — it is downstream of the registration act,
  exactly as this question suspected.
- ~~Do resources exist per event only, or does a venue-level catalogue get
  reused across a brand's events? The second saves setup work and complicates
  brand scoping.~~ **Decision log Round 6 (D-EVT-13, user override of the
  recommendation):** per event only — no venue-level catalogue at release.

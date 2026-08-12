# Event — Specification

> Stable document. Describes what this module *is*. Task tracking lives in
> `TODO.md`.

## Purpose

Event owns the concrete occurrence a brand puts on: its identity, its dates, its
lifecycle state, and the venue it happens at. It absorbs LanCore's Event and
Venue domains, with Venue deliberately demoted to a sub-concept rather than
promoted to a module of its own. Event is the axis almost everything else hangs
off — registrations, admissions, resource bookings, shifts, schedule items and
tournaments all carry an `event_id` as a plain ULID (ADR-0014). That makes
restraint the design goal: the more Event owns, the more every other module is
tempted to reach into it. LanCore's Event domain imported twenty-one foreign
models (lessons-from-lancore #1); this one imports none.

## Owns

- **Event**: name, slug, brand association via `BelongsToBrand` (ADR-0010),
  start and end, timezone, a short public description, and the canonical
  timeframe other modules ask about.
- **Event lifecycle** as explicit states with transitions modelled as Actions:
  draft, announced, published, running, concluded, archived — plus cancellation
  as a first-class transition with a reason, not a soft delete.
- **Venue** as a sub-concept of Event: postal address, geo coordinates, access
  and arrival notes, opening times of the site. A venue is brand-owned and
  reusable across that brand's events.
- **Event visibility**: whether an unpublished event is reachable by direct link,
  and the public landing route resolved on the brand's own domain.
- **The event's own descriptive metadata** used by other modules for display:
  name, dates, timezone, venue city. Modules keep this as read models built from
  Event's events rather than querying Event tables.

## Does not own

| Nearby concept | Owned by |
|---|---|
| Brand, domains, theme, module activation, static brand pages | Platform |
| Registration types, their axes, quotas, the commercial act | Registration (ADR-0005) |
| A person's access right, badge, check-in | Admission (ADR-0005) |
| Seats, rooms, booths, devices and their time-dimensioned bookings | Resources — capacity is not an attribute of an event |
| Programme items, talks, timetable | Agenda |
| Tournaments, matches, results | Competition |
| Game server provisioning for an event's matches | GameOps (ADR-0012) |
| Volunteer teams, shifts, assignments | Staffing |
| News and announcements about the event | Content |
| Orders, invoices, prices, payment terms | Commerce (ADR-0006) |
| Sales windows, check-in windows, booking windows | the module that owns the process — Event owns only the event's own timeframe |
| Contracts with the venue operator, room rental, insurance | not modelled; out of scope |

The recurring temptation is capacity. "How many people fit" looks like an event
attribute and is not: quota is a standalone Registration entity, physical space
is a Resources concern, and the two answer different questions.

## Public contract (events)

Events are the only way other modules may react to this one (ADR-0004).
Payloads carry ULIDs and scalars — never Eloquent models.

| Event | Emitted when | Payload | Status |
|---|---|---|---|
| `EventCreated` | An event is created under a brand | `event_id`, `brand_id`, `slug`, `name`, `starts_at`, `ends_at`, `timezone`, `predecessor_event_id` | implemented |
| `EventDetailsUpdated` | Name, slug or description changes | `event_id`, `brand_id`, `slug`, `name` | implemented |
| `EventAnnounced` | Draft → Announced: teased, not yet fully published | `event_id`, `brand_id`, `announced_at` | implemented |
| `EventPublished` | The event becomes publicly visible | `event_id`, `brand_id`, `published_at` | implemented |
| `EventUnpublished` | The event is withdrawn from public view | `event_id`, `brand_id` | implemented |
| `EventStarted` | Published → Running — doors open, an explicit operator transition (never clock-derived) | `event_id`, `brand_id`, `started_at` | implemented |
| `EventRescheduled` | Start, end or timezone changes | `event_id`, `starts_at`, `ends_at`, `timezone` | implemented |
| `EventCancelled` | The event will not take place | `event_id`, `brand_id`, `reason_code`, `cancelled_at` | implemented |
| `EventConcluded` | The event has ended | `event_id`, `brand_id`, `concluded_at` | implemented |
| `EventArchived` | The event is closed for all operations | `event_id`, `brand_id`, `archived_at` | implemented |
| `EventDaysChanged` | The event's day set changed (D-ADM-04). Always carries the COMPLETE current set — consumers replace, never patch | `event_id`, `brand_id`, `days` (list of `{day_id, date, sequence, label}`) | implemented |
| `VenueCreated` | A venue is created for a brand | `venue_id`, `brand_id`, `name`, `city`, `country_code` | implemented |
| `VenueUpdated` | Venue address or access details change | `venue_id`, `brand_id`, `name`, `city`, `country_code` | implemented |
| `VenueAssigned` | A venue is attached to an event (D-EVT-15: says whether it is the primary) | `event_id`, `venue_id`, `brand_id`, `name`, `city`, `is_primary` | implemented |
| `VenueUnassigned` | A venue is detached from an event | `event_id`, `venue_id`, `brand_id` | implemented |

`EventRescheduled` and `EventCancelled` are the two consumers care about most:
they invalidate schedules, shift plans, bookings and admissions across half the
system. `EventConcluded` is what starts retention clocks in Compliance.

## Consumes

Events from other modules that this module listens to, and the read models it
maintains from them.

| Event | Owning module | Why |
|---|---|---|
| `BrandArchived` | Platform | Archive the brand's events rather than leaving them reachable |
| `DataExportRequested` | Compliance | Contribute any organiser contact details held on an event to the export |
| `UserDeletionRequested` | Compliance | Anonymize organiser contact references; usually a no-op, and the handler says so explicitly rather than being absent |

Event keeps no read models of participant data. Anything it would want to
display about registrations or attendance is a query the *other* module answers
on its own page.

## Capabilities

Declared in `src/Enums/Permission.php`. Roles that grant them are per-brand
data, not code (ADR-0013).

Proposed catalogue: `ViewEvents`, `CreateEvent`, `UpdateEvent`, `PublishEvent`,
`RescheduleEvent`, `CancelEvent`, `ArchiveEvent`, `ViewVenues`, `ManageVenues`.

## Activation

Kernel module. Always active for every brand and not gateable: its provider sets
`$gateable = false` and its routes carry no `ensure-module` middleware. A brand
without events has nothing to run, and every gated module resolves its own
feature against an event's brand context, which requires the event to exist
unconditionally.

## Open questions

- ~~Editions and series: is "SXLan 2027 is the successor of SXLan 2026"
  modelled here as a predecessor reference, or is it left to naming
  convention? A reference would let Registration and Community carry state
  forward.~~ **Decision log Round 6 (D-EVT-14, user override of the
  naming-convention default):** a `predecessor_event_id` ULID reference from
  the start.
- ~~Can an event be co-branded, or does exactly one brand own it?
  `BelongsToBrand` assumes the latter; a joint event between two of the
  operator's brands would break the assumption.~~ **Decision log Round 6
  (D-EVT-15):** confirmed — exactly one brand per event. `BelongsToBrand`'s
  assumption stands; co-branding is not supported.
- ~~Multi-venue events, and purely online events with no venue: is Venue
  optional, or is there a null-venue representation? Partially settled:
  `Event::venue_id` is nullable, so a single event with no venue at all is
  supported today. Multi-venue (more than one venue per event) is still
  open.~~ **Decision log Round 6 (D-EVT-15, user override):** MULTI-venue
  ships at release — an event carries a venue list with a primary, not a
  single nullable `venue_id`. Zero venues (online-only) remains representable
  as an empty list.
- ~~Are event-local times stored in UTC with a display timezone, or stored
  wall-clock with the timezone as data?~~ **Settled by ADR-0027**: wall-clock
  local time plus an IANA `timezone` column. Conversion to instants happens
  only through the model's `startsAtInstant()`/`endsAtInstant()` accessors.
- ADR-0003 allows per-event feature scoping through Pennant. If Event is the
  scope object, does Event or Platform own the mapping from request to scope?
  **Decision log Round 6 (D-BATCH):** deferred — per-event Pennant scoping
  does not ship at release; mirrored in Platform's SPEC.

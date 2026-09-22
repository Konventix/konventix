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
- **Venue** as a sub-concept of Event: postal address, geo coordinates of the
  building *and* of its entrance, access and arrival notes, how to get there by
  public transport, opening times of the site, parking spots and loading zones
  (each a label with an optional address and coordinate pair, behind one typed
  cast), a seat plan reference, a description, and an ordered set of pictures
  from the brand's media library (ADR-0035) with one primary — held as media
  ULIDs with usage bookkeeping, never files. A venue is brand-owned and
  reusable across that brand's events, and carries a per-brand unique `slug`
  naming its public page.
- **Event visibility**: whether an unpublished event is reachable by direct link,
  and the public landing route resolved on the brand's own domain. A **venue**
  has no visibility of its own: `GET /venues/{venue:slug}` resolves exactly
  while at least one event assigned to that venue is itself visible on its
  landing page (`Venue::isPubliclyReachable()`, the query form of
  `Event::isVisibleOnLanding()`), and 404s otherwise — a venue an operator
  keeps for next year stays a console record. The page links back from each
  event landing page's venue list, where the venue also wears its primary
  picture. "Open in maps" is a plain OpenStreetMap link from the entrance's
  coordinates, falling back to the building's; there is no embedded map and no
  map dependency.
- **The event's days**, including **when a day ends for the people inside it**
  (`event_days.day_ends_at`, D-HUB-01): a nullable `HH:MM` string, null meaning
  00:00 of the following day, so a LAN day that runs until 03:00 keeps the
  live hub open until 03:00 instead of losing it at midnight. Stored as text
  rather than a `time` column so the value round-trips unchanged through the
  days editor, `EventDaysChanged` and Admission's projection of it. Event owns
  the setting; what it *means* for a person's access is Admission's — the
  date a scan belongs to is still `dayCovering()`'s answer, unchanged, and
  `day_ends_at` only fixes when that day's access window closes.
- **The live hub's per-event composition** (`event_hub_layouts`,
  `Models\EventHubLayout`, design 2026-09-22 §6, D-HUB-06). One row per event:
  the order and visibility of the cards and links Platform's registry holds,
  as opaque keys (`Data\HubLayoutEntryData`), plus `hub_replaces_home` — the
  operator's choice to let the brand's home page be the hub for signed-in
  admitted members while this event runs. Written whole on every save by
  `Actions\SaveEventHubLayout`, from the admin page
  `admin/events/{event}/hub` — behind `Permission::UpdateEvent`, the same
  capability that edits the event itself — which lists what Platform's
  `HubCatalogue` reports. Event owns *which* cards and in which order; it owns
  neither the cards themselves nor the page they render on, and it stores
  Platform's keys rather than Platform's objects (ADR-0004).
- **The event's own descriptive metadata** used by other modules for display:
  name, dates, timezone, venue city. Modules keep this as read models built from
  Event's events rather than querying Event tables.
- **The public calendar download** — `GET /events/{event:slug}/calendar.ics`
  (public, unauthenticated, 404 for an event that is not publicly visible,
  matching the landing page). The RFC 5545 encoding itself is Platform's
  `Calendar\CalendarFile::forEvent(...)` (ADR-0061): Event calls it with its
  own scalars — name, description, `starts_at`/`ends_at` converted through
  `startsAtInstant()`/`endsAtInstant()`, `timezone`, the primary venue's
  name and city, and the public event URL — the same scalars-in-bytes-out
  shape every module already uses for `MediaLibrary`. Registration's own
  `registration_event_summaries` carries the same shape for the
  confirmation mail's attachment; Admission keeps a second copy for the
  ticket's event band. Three read models of the same facts, not three
  owners of them — neither consumer may read Event's models directly
  (ADR-0004).

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
| `EventCreated` | An event is created under a brand | `event_id`, `brand_id`, `slug`, `name`, `starts_at`, `ends_at`, `timezone`, `predecessor_event_id`, `visibility`, `banner_media_id` | implemented |
| `EventDetailsUpdated` | Name, slug, description, visibility or banner changes | `event_id`, `brand_id`, `slug`, `name`, `visibility`, `banner_media_id` | implemented |
| `EventAnnounced` | Draft → Announced: teased, not yet fully published | `event_id`, `brand_id`, `announced_at` | implemented |
| `EventPublished` | The event becomes publicly visible | `event_id`, `brand_id`, `published_at` | implemented |
| `EventUnpublished` | The event is withdrawn from public view | `event_id`, `brand_id` | implemented |
| `EventStarted` | Published → Running — doors open, an explicit operator transition (never clock-derived) | `event_id`, `brand_id`, `started_at` | implemented |
| `EventRescheduled` | Start, end or timezone changes | `event_id`, `brand_id`, `starts_at`, `ends_at`, `timezone` | implemented |
| `EventCancelled` | The event will not take place | `event_id`, `brand_id`, `reason_code`, `cancelled_at` | implemented |
| `EventConcluded` | The event has ended | `event_id`, `brand_id`, `concluded_at` | implemented |
| `EventArchived` | The event is closed for all operations | `event_id`, `brand_id`, `archived_at` | implemented |
| `EventDaysChanged` | The event's day set changed (D-ADM-04). Always carries the COMPLETE current set — consumers replace, never patch | `event_id`, `brand_id`, `days` (list of `{day_id, date, sequence, label, day_ends_at}` — `day_ends_at` (`HH:MM`, nullable) joined the day entry additively for the live hub, D-HUB-01) | implemented |
| `EventReentryPolicyChanged` | The re-entry knob changed, **and at creation** — a consumer that hears only about changes never learns the policy of an event that was right from the start. Exists because ADR-0033 needs the policy decidable at a door with no network, and Admission may not read this module's models (ADR-0004). Carries the complete current value, like `EventDaysChanged` | `event_id`, `brand_id`, `reentry_policy` | implemented |
| `EventAttendeeListPolicyChanged` | The attendee-list switch changed, **and at creation** (ADR-0064 §6) — same shape and same reason as `EventReentryPolicyChanged`: Registration renders the list from its projection and may not read this module's models (ADR-0004). Carries the complete current value, `off`/`members`/`public` | `event_id`, `brand_id`, `attendee_list_visibility` | implemented |
| `EventHubLayoutChanged` | The operator saved the live hub's composition for an event (D-HUB-06). Carries the complete layout, never a diff, like `EventDaysChanged`: Platform replaces its projection row and forgets its cache. The card keys are Platform's registry keys, opaque here (ADR-0004) | `event_id`, `brand_id`, `cards` (list of `{key, visible}` in display order), `hub_replaces_home` | implemented |
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

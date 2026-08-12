# Event / Resources / Agenda / Staffing — LanCore inventory

What LanCore actually built in its Event, Venue, Seating, Program and OrgaTeam
domains, and what each feature becomes in Konventix. Two generalisations drive
the whole document and are already settled: **Seat becomes one Resource type
with a time dimension**, and **OrgaTeam becomes Staffing** (teams, shifts, shift
roles, assignments, plus a `StafferProfile` read model fed by Identity events,
ADR-0004).

The recurring theme is that LanCore's models were shaped by one event format.
A seat is a fixed point on a canvas held for the whole weekend by exactly one
gamer; an "Orga-Team" is an org chart, not a workforce; a "Program" is an
ordered list of moments without end times. Konventix has to serve a LAN party,
a conference, a trade fair and a club tournament from the same tables, so most
rows below are `Generalize`, not `Port as-is`.

## Sources

LanCore (read-only reference, `/home/mkohn/git/lan-software/LanCore`):

- `docs/mil-std-498/SRS.md` §3.2.1 (CSCI-EVT, EVT-F-001..013), §3.2.4
  (CSCI-PRG, PRG-F-001..007), §3.2.5 (CSCI-SET, SET-F-001..019), §3.2.6a
  (CSCI-OT, OT-F-001..010), §3.2.15 (CSCI-VEN, VEN-F-001..003), §3.2.18
  (CSCI-ORG, ORG-F-001..006); plus §3.2.2 TKT-F-016 and §3.2.FF PUB-F-001..008
  where they reach into event capacity and event attributes.
- `app/Domain/Event/`, `app/Domain/Venue/`, `app/Domain/Seating/`,
  `app/Domain/Program/`, `app/Domain/OrgaTeam/` — models, actions, policies,
  resources, support classes.
- `resources/js/pages/{events,seating,programs,orga-teams}/`,
  `resources/js/components/orga-team/OrgChart.vue`.
- `docs/mil-std-498/RTM.md` (for SET-F-017, which the SRS body omits).

Konventix: `CLAUDE_extra.md`, `docs/architecture/overview.md`,
`docs/architecture/module-map.md`, `docs/lessons-from-lancore.md`,
`docs/decisions/0001,0004,0005,0012,0013`, `docs/ROADMAP.md`, and the current
`SPEC.md` of `app-modules/{event,resources,agenda,staffing,registration}`.

Verified in code, not only in the SRS: LanCore has **no** shift, assignment,
availability, qualification or coverage concept anywhere (`grep -ril shift app/`
returns three unrelated files). Staffing is therefore roughly 85 % net-new work
that the SRS cannot help with — OrgaTeam contributes the *display* half of it.

## Feature inventory

| LanCore feature | SRS ID | What it does today | Konventix module | Proposed name | Verdict |
|---|---|---|---|---|---|
| Event CRUD via `EventController` | EVT-F-001 | Admin create/edit/delete of events | Event | `Event` + CRUD Actions | Port as-is |
| Event core fields (name, description, start_date, end_date, status) | EVT-F-002 | Columns on `events` | Event | `Event` (+ `timezone`, `slug`) | Port renamed |
| Event presentation columns: `banner_images` JSON array, `theme_id` | EVT-F-002 (code) | Untyped array of banner URLs plus a per-event theme from the Theme library | Platform | event media collection + brand design tokens (ADR-0008) | Generalize |
| `events.seat_capacity` + `remainingSeatCapacity()` | EVT-F-002, TKT-F-016 | One integer on the event; consumption computed as `seats_per_user × max_users_per_ticket` across sold tickets and addons | Registration + Resources | `Quota` (headcount) / resource inventory (space) | Split across modules |
| `EventPolicy` on all event operations | EVT-F-003 | Single `ManageEvents` permission gates everything | Event | `Permission` enum: `ViewEvents`, `CreateEvent`, `UpdateEvent`, `PublishEvent`, `RescheduleEvent`, `CancelEvent`, `ArchiveEvent` | Generalize |
| Two-state lifecycle draft ↔ published | EVT-F-004 | `EventStatus` enum with one reversible transition | Event | `EventStatus`: draft, announced, published, running, concluded, archived + `cancel()` | Generalize |
| `EventPublished` domain event → webhook listener | EVT-F-005 | Fires `HandleEventPublishedWebhooks` in-process | Event (emit) / Extensibility (webhook fan-out) | `EventPublished` | Split across modules |
| Venue with name, description, Address (street/city/state/country) and geo (`latitude`, `longitude`, `country_code`) | EVT-F-006, VEN-F-001 | Own domain, own policy, `venues.address_id → addresses`, decimal(10,7) geo columns fed to the LAN directory | Event | `Venue` as Event sub-concept, address and geo inlined | Port renamed |
| Venue images with alt text + sort order | EVT-F-007, VEN-F-002 | `venue_images` table | Event | venue media collection | Defer post-release |
| `VenuePolicy` / `ManageVenues` | VEN-F-003 | Separate domain permission | Event | `ViewVenues`, `ManageVenues` in Event's enum | Port renamed |
| Per-domain audit controllers and `Auditable` rows (event, program, seat plan / block / row / seat / label) | EVT-F-008, PRG-F-007, SET-F-005 | Each domain ships its own audit controller over `owen-it/auditing` rows | Compliance | central audit trail with per-entity views | Split across modules |
| Public event listing / public event page | EVT-F-009 | `PublicEventController` + `events/Public.vue` | Event | public event routes on the brand domain | Port as-is |
| `events.primary_program_id` | EVT-F-010 | One program singled out per event | Agenda | (dropped — Agenda owns programme structure) | Drop |
| Per-user "my event context" session selection | EVT-F-011 | `POST/DELETE /my-event-context`, session key `my_selected_event_id`, validated through `Event::scopeForUser`, filters four my-* views | Platform | event context resolution + per-module participation predicate | Split across modules |
| `Event::scopeForUser` participation scope | EVT-F-011 (code) | One query joining tickets, competition teams and orders — three foreign domains from Event | (each module) | each module answers "does this user participate?" for itself | Drop |
| iCalendar export `GET /events/{event}/calendar.ics` | EVT-F-012 | RFC 5545 VCALENDAR with venue address as `LOCATION`, 404 unless published | Event | `BuildEventIcal` | Port as-is |
| LPPS event columns: `attendance_mode`, `has_showers`, sleeping/alcohol/smoking/age/food policy bitsets, network/internet/wifi Mbps, `syndication_status` | EVT-F-013, PUB-F-006, PUB-F-007 | Ten LAN-directory columns on `events`, bitsets via `InteractsWithBitset`; `syndication_status` controls inclusion in the published document | GameOps (LAN plugin) | `EventLanProfile` keyed by `event_id` | Generalize |
| `/.well-known/lan-party.json` publishing document | PUB-F-001..008 | Cached JSON feed of org + published events + venues + ticket types | GameOps (LAN plugin) | LPPS publisher | Defer post-release |
| `EventDashboardStats` | (code, no SRS ID) | One service aggregating tickets sold, seats taken, check-ins across Ticketing + Seating from inside Event | Platform (host) + each module (widget) | dashboard UI slots | Drop |
| `SoftDeletes` on Event | (code) | Deleted events linger as `deleted_at` rows | Event | `cancel(reason)` + `archive()` transitions | Drop |
| `events.orga_team_id` FK (`ON DELETE SET NULL`) | OT-F-005 | Event points at at most one Orga-Team | Staffing | `Team.event_id` — ownership inverted | Generalize |
| Programs belonging to an event (name, description, sort_order) | PRG-F-001 | Multiple named programmes per event | Agenda | `Track` | Port renamed |
| Program visibility public / registered_only / hidden | PRG-F-002 | Enum column on program and time slot | Agenda | item status (draft/published/cancelled) + audience predicate | Generalize |
| `TimeSlot` with `starts_at`, ordering, visibility | PRG-F-003 | A moment, a sort order, and no end time | Agenda | `AgendaItem` with `starts_at` **and** `ends_at` | Generalize |
| `ProgramTimeSlotApproaching` event + `ProgramNotificationSubscription` | PRG-F-004, PRG-F-005 | Scheduled domain event for reminders, with per-user opt-in stored in the Notification domain | Agenda (emit) / Messaging (deliver + subscribe) | `AgendaItemStarting` + notification subscription | Split across modules |
| Sponsor assignment to programs and time slots | PRG-F-006 | Many-to-many pivots from Sponsoring into Program | Sponsoring | sponsor placement | Defer post-release |
| Canvas seat-plan editor (drag-place, marquee select, pan, zoom, delete tool, undo/redo, shortcuts) | SET-F-001 | Admin `EditorShell` / `EditorCanvas` / `PropertiesPanel` | Resources | resource plan editor | Port renamed |
| Normalized plan schema `seat_plans → blocks → rows → seats` + `labels`, plans scoped to an event | SET-F-002, SET-F-003 | Four-level tree with cascading FKs rooted at `seat_plans.event_id` | Resources | `ResourcePlan(event_id, venue_id) → ResourceGroup → Resource` (+ layout labels) | Generalize |
| `SeatPlanPolicy` / `ManageSeatPlans` | SET-F-004 | One permission for the whole domain | Resources | split catalogue: manage types / manage resources / manage availability / book for others / book own / view roster | Generalize |
| `SeatAssignment` = (ticket, user, seat_plan, seat), unique on (plan, seat) and (ticket, user) | SET-F-006 | The whole booking model: no interval, no state, no hold | Resources | `ResourceBooking` (holder + half-open interval + state) | Generalize |
| Who may pick a seat: owner, manager, assignee, `ManageTicketing`, blocked after check-in | SET-F-007 | `TicketPolicy::pickSeat` — a *Ticketing* policy governing a *Seating* write | Resources | `ResourceBookingPolicy` + booking window on `ResourceType` | Generalize |
| Auto-release on ticket cancelled / deleted / user removed from group ticket | SET-F-008 | Model observers reaching into Ticketing | Resources | listeners on `RegistrationCancelled` / `AdmissionRevoked` | Port renamed |
| Public plan showing assignee initials, gated by the `users.is_seat_visible_publicly` toggle, overridden for viewers holding an active ticket | SET-F-009, SET-F-010 | Cross-domain render rule: Seating + Identity privacy preference + Ticketing viewer check, with a Settings → Privacy toggle | Resources + Identity + Admission | occupancy view with a privacy predicate; the preference itself moves to Identity | Split across modules |
| Per-block ticket-category allowlist (empty = open) | SET-F-011 | `seat_plan_block_category_restrictions` pivot into Ticketing | Resources | eligibility tags on `ResourceGroup`, matched against a claim | Generalize |
| Invalidation diff + explicit admin confirmation on plan save | SET-F-012 | Diffs proposed tree against existing assignments, blocks the save | Resources | withdrawal impact analysis | Port renamed |
| Transactional release + `SeatAssignmentInvalidated` per assignment | SET-F-013 | Emitted once per released assignment | Resources | `ResourceBookingReleased(reason: resource_withdrawn)` | Port renamed |
| Notify owner, manager and assignee of invalidation (mail + in-app, push opt-in) | SET-F-014 | `NotifyAffectedAssignees` listener with per-user channel prefs | Messaging | notification on `ResourceBookingReleased` | Split across modules |
| Background image per plan and per block | SET-F-015 | `background_image_url` columns + upload endpoints | Resources | plan/group background | Port as-is |
| Legacy `{blocks:[{seats,labels,...}]}` wire shape preserved for `@alisaitteke/seatmap-canvas` | SET-F-016 | `SeatPlanResource` shapes output for a third-party canvas library | — | — | Drop |
| Legacy JSONB → normalized backfill (`LegacySeatPlanConverter`, `seating:migrate-json`) | SET-F-017 | One-off migration tooling | — | — | Drop |
| `seat_title_prefix` per block, prepended at render time | SET-F-018 | Raw title in storage, prefixed title on the wire | Resources | label template on `ResourceGroup` | Port renamed |
| Editor save feedback: Saving… / Saved / red error banner, plus a `canvas-confetti` burst on success | SET-F-019 | Inline editor states; confetti coloured by the plan palette, suppressed under `prefers-reduced-motion` | Resources | editor save states (confetti dropped) | Port renamed |
| `seats.salable` boolean | (code) | Non-salable seats render but cannot be assigned | Resources | `bookable` flag + `Availability` windows | Generalize |
| `seats.custom_data` JSON blob | (code) | Untyped escape hatch on every seat | Resources | typed attributes declared by `ResourceType` (ADR-0009 forbids the blob) | Generalize |
| `seats.x` / `seats.y` / `color` / `note` | (code) | Canvas position and presentation | Resources | `Resource.position_x/y`, presentation attributes | Port as-is |
| Rows as a grouping level between block and seat | SET-F-002 (code) | `seat_plan_rows` exists mainly for row-based numbering | Resources | numbering rule on `ResourceGroup` | Generalize |
| Orga-Team: name, unique slug, description, `organizer_user_id` | OT-F-001 | A standing, event-independent org unit | Staffing | `Team` scoped to an event (org-unit reuse becomes a template) | Generalize |
| N ordered deputies per Orga-Team | OT-F-002 | `orga_team_deputies` pivot with `sort_order`, organizer excluded | Staffing | team leads | Port renamed |
| Sub-Teams: name, description, emoji, accent colour, sort order, leader | OT-F-003 | One nesting level below the Orga-Team | Staffing | `Team` with `parent_id` | Generalize |
| Sub-Team membership with role enum (Deputy / Member) + sort order | OT-F-004 | Standing membership; a user may sit in several sub-teams | Staffing | `TeamMembership` (standing) — distinct from `ShiftRole` (per-shift function) | Split across modules |
| `OrgaTeamPolicy` / `OrgaSubTeamPolicy` on `ManageOrgaTeams` | OT-F-006 | One permission for every write path | Staffing | manage teams / manage shift roles / manage shifts / assign others / claim own / view roster | Generalize |
| Public `GET /events/{event}/orga-team` org chart, 404 when unassigned | OT-F-007 | `OrgChart.vue` — the visible product of the whole domain | Staffing | public crew page (opt-in per team) | Port renamed |
| `ResolveWelcomeEvent` (next published event with `start_date >= today`) | OT-F-008 | Decides which event the landing page shows | Platform | current-event resolution for a brand | Generalize |
| `EventLayout` with a right-hand content slot, stacking below `lg` | OT-F-009 | Vue layout that event-bound widgets plug into | Platform | UI slot registry | Generalize |
| Orga-Team membership is permission-neutral | OT-F-010 | Being on a team grants no system rights | Staffing | keep the rule; roles are per-brand data (ADR-0013) | Port as-is |
| Shifts, shift roles, assignments, coverage, qualifications, availability | — | **Does not exist in LanCore** | Staffing | `Shift`, `ShiftRole`, `Assignment`, coverage query | Generalize (net-new) |
| `StafferProfile` read model | — | **Does not exist**; LanCore joined `User` directly from OrgaTeam | Staffing | `StafferProfile` fed by Identity events (ADR-0004) | Generalize (net-new) |
| Organization identity in a key/value settings table (name, logo, address, legal notice) | ORG-F-001 | Schemaless settings rows | Platform | `Operator` + `Brand` rows (ADR-0001) | Generalize |
| Organization settings admin surface: admin-only routes, `organization` shared Inertia prop `{ name, logoUrl }` on every page, `inertia.organization` cache key with 1 h TTL invalidated on every write, logo upload/removal on the public disk | ORG-F-002..005 | Global settings screen, one shared prop, hand-rolled cache invalidation | Platform | capability-gated brand settings, brand shared prop, brand resolution cache, brand media | Port renamed |
| LPPS organisation keys (`lpps_description`, Steam group, Discord invite, publisher id) | ORG-F-006 | Four more key/value rows for the LAN directory | GameOps (LAN plugin) | LAN publisher profile | Defer post-release |

60 rows.

## Seating -> Resources: the generalisation, feature by feature

The claim to test is that a seat is one `ResourceType` with a time dimension,
not a special case. Read `Survives?` as: does the capability still exist once
the vocabulary is generic, and does it still mean something for a meeting room,
a booth strip and a loanable headset?

| LanCore seating capability | Generic re-expression | Survives? |
|---|---|---|
| `SeatPlan` per event | `ResourcePlan` per event, referencing `venue_id`; optional — a resource type without a spatial layout (device pool) has resources but no plan | Yes |
| `SeatPlanBlock` (visual grouping, colour, title) | `ResourceGroup` — grouping, presentation, and the carrier of eligibility rules | Yes |
| `SeatPlanRow` | numbering/labelling rule on `ResourceGroup`; rows are how seats are *named*, not a structural level rooms or devices need | Yes, demoted |
| `SeatPlanSeat` | `Resource` — one bookable unit with `resource_type_id`, label, typed attributes, optional position | Yes |
| `seats.x` / `y` | `Resource.position_x/y`, populated only for types whose plan is spatial | Yes, optional |
| `SeatPlanLabel` | plan annotation — pure presentation, unchanged | Yes |
| `background_image_url` on plan and block | plan/group background image | Yes |
| `seat_title_prefix` on a block | label template on `ResourceGroup` | Yes |
| `seats.salable = false` | `Resource.bookable = false`, or an `Availability` row covering the whole event as a blackout — the two mean different things and the split is an improvement (a permanently broken seat vs. a room blocked Friday for setup) | Yes, strengthened |
| `seats.custom_data` JSON | typed attributes declared on `ResourceType` (power outlet, monitor arm, floor area m², HDMI) — ADR-0009 forbids the blob, and this is where per-seat power/network belongs once it exists | Yes, retyped |
| `SeatAssignment` (ticket, user, plan, seat) | `ResourceBooking` (`resource_id`, `holder_type`, `holder_id`, `starts_at`, `ends_at`, state) — the LanCore row is the degenerate case `[event.starts_at, event.ends_at)` | Yes, generalised |
| Unique `(seat_plan_id, seat_plan_seat_id)` | no-overlap invariant on `(resource_id, interval)` up to the type's capacity; for capacity 1 and a whole-event interval this reduces to exactly the old constraint | Yes, strengthened |
| Unique `(ticket_id, user_id)` — one seat per person per ticket | a per-`ResourceType` rule `max_bookings_per_holder`; LanCore hardcoded 1, which is wrong for device loans and booth strips | Yes, parameterised |
| Ticket + user as the assignment key | `holder_type` / `holder_id` ULID: registration, admission, shift, organizer — this is what lets a booth belong to a company and a seat to a person | Yes, generalised |
| `AssignSeat` validation: seat exists, is salable, belongs to the ticket's event, assignee is on the ticket | booking validation: resource is bookable, belongs to the event, interval is inside availability, holder is entitled by a claim | Yes |
| Auto-release on ticket cancel / delete / group-user removal | listeners on `RegistrationCancelled` and `AdmissionRevoked` releasing bookings by holder | Yes |
| Invalidation diff on plan save + admin confirmation | withdrawal impact analysis: which bookings does removing this resource or narrowing this availability break? | Yes, broader |
| `SeatAssignmentInvalidated` | `ResourceBookingReleased(reason)` | Yes |
| Per-block ticket-category allowlist (`allowed_ticket_category_ids`) | eligibility tags on `ResourceGroup` matched against tags carried by the registration's claim — Resources must not import `TicketCategory`/`RegistrationType` (ADR-0004), so the coupling has to invert | **Only with a redesign** — see D-EVT-07 |
| Public plan showing assignee initials, gated by `is_seat_visible_publicly`, always revealed to viewers holding an active ticket | an occupancy view whose privacy predicate needs Identity's display name, Identity's preference and Admission's viewer status — three modules, and the *defaults* differ per resource type (a seat neighbour is social; a meeting-room booking is scheduling metadata; a device loan is asset tracking) | **Worst fit** — see below |
| Canvas picker (click a seat on a map) | picker per `ResourceType`: map for spatial types, list/calendar for rooms and devices — the plan renderer becomes one of several pickers rather than *the* UI | Yes, with a second UI |
| Seat capacity consumption `seats_per_user × max_users_per_ticket` (TKT-F-016) | nothing — it conflates a sales limit with physical inventory. Quota is Registration's standalone entity; inventory is a count of `Resource` rows | **No — dropped by design** |
| `SeatPlanResource` wire shape for `@alisaitteke/seatmap-canvas` | nothing; a third-party canvas library's payload shape is not a domain concept | **No — dropped** |
| Confetti on save | nothing | **No — dropped** |

**The capability that generalises worst: SET-F-009**, the socially visible seat
plan. It is not hard because of privacy law; it is hard because it is the only
seating feature whose *meaning* is LAN-specific. "Who is sitting next to me" is
a headline participant feature at a BYOC party and the reason people open the
seat plan at all. The same query against meeting rooms is an internal
scheduling view, against booth space it is a public exhibitor directory, and
against loanable devices it is asset tracking with a completely different
default (nobody publishes who borrowed which headset). One generic
"occupancy visibility" flag cannot carry four different defaults, and the
LanCore implementation additionally needs data from three modules that ADR-0004
forbids Resources from importing: Identity's display name, Identity's
`is_seat_visible_publicly` preference, and Admission's "does the viewer hold an
active admission for this event" check.

The honest re-expression is: `ResourceType.occupancy_visibility` (`none` /
`anonymous` / `initials` / `name`) with a per-type default, `Identity` owning
the participant's opt-out, and the viewer-eligibility override expressed as a
policy question Admission answers — not a join Resources performs. The BYOC
seat plan then remains one configuration of a generic mechanism instead of the
mechanism itself, which is the right outcome but is materially more code than
LanCore's single boolean.

Runner-up: the per-block ticket-category allowlist (SET-F-011). It survives,
but only after inverting the dependency (D-EVT-07).

## Renames

| LanCore term | Konventix term | Why the rename forces a better model |
|---|---|---|
| `SeatPlanSeat` | `Resource` | Removes the assumption that the unit is a place where one person sits for the duration. A resource is a thing with an identity, a type, and availability — which is also true of a room, a booth and a projector. |
| `SeatAssignment` | `ResourceBooking` | "Assignment" hides the fact that a claim has a lifetime. Once it is a booking it obviously needs `starts_at`, `ends_at` and a state machine (held → confirmed → released), which is exactly what LanCore lacked and what makes rooms and devices possible at all. |
| `seat_plan_id` + `seat_plan_seat_id` on the assignment | `holder_type` + `holder_id` on the booking | LanCore keyed the assignment by the *ticket and the user* — one purchase, one person, one seat. Naming the other side "holder" makes it visible that a booth is held by a registration, a seat by an admission, and a room by a shift or an agenda item. |
| `seats.salable` | `Resource.bookable` + `Availability` | "Salable" is a Commerce word on a Resources column, and it conflated two states: this unit does not exist for booking at all, and this unit is unavailable during these hours. Splitting them is what introduces the time dimension. |
| `allowed_ticket_category_ids` | eligibility tags on `ResourceGroup` | The old name embeds a foreign module's entity in this module's schema. Tags force the question "what property of the claim makes it eligible?", which is answerable without Resources knowing what a RegistrationType is. |
| `events.seat_capacity` | `Quota` (Registration) + resource inventory (Resources) | One number was answering two questions — how many may register, and how many fit. They diverge the moment an event has exhibitors, crew and day tickets. Deleting the column forces both answers to be computed by the module that owns them. |
| `OrgaTeam` / `OrgaSubTeam` | `Team` (with `parent_id`) | "Orga-Team" names an org chart. "Team" names a unit of a workforce, which is the thing shifts belong to. The German-internal compound also fails an event-agnostic, English-first domain language. |
| `OrgaSubTeamMembership.role` (`Deputy` / `Member`) | `TeamMembership` + `ShiftRole` | One enum was doing two jobs: standing seniority inside a team, and the function a person fills during a specific shift. Separating them is what makes "we need two first-aiders and one runner on Saturday night" expressible. |
| `Program` | `Track` | "Program" reads as *the* programme, which is why LanCore needed `events.primary_program_id` to disambiguate. `Track` is plainly one of several groupings, so the pointer disappears. |
| `TimeSlot` | `AgendaItem` | A slot is a container you put something into; LanCore's had `starts_at` and no `ends_at`, so nothing could be laid out on a timetable or checked against a room booking. An item is the thing itself and obviously has a duration. |
| `OrganizationSetting` key/value rows | `Operator` / `Brand` rows | Schemaless settings are forbidden (ADR-0009), and one operator now runs several public identities. Typed rows are what make per-brand invoice ranges, themes and module activation possible (ADR-0001). |
| `ResolveWelcomeEvent` | current-event resolution (Platform) | The name describes a landing page; the behaviour is "which event is this brand currently about", which several modules need. Moving it to Platform stops Staffing from owning a navigation concern. |

## Dropped / deferred, and why

**Dropped outright**

- **`events.primary_program_id` (EVT-F-010).** An artefact of calling a track a
  "program". With `Track` there is no primary one.
- **`Event::scopeForUser` (EVT-F-011).** A single query joining Ticketing,
  Competition and Shop from inside Event — precisely the coupling ADR-0004
  forbids. Each module answers participation for itself; the event-context
  selector asks them, or asks nothing and lets each page filter.
- **`EventDashboardStats`.** One service in Event computing ticket sales, seat
  occupancy and check-in counts by joining four foreign tables. Becomes
  dashboard UI slots that each owning module fills (ADR-0003).
- **`SoftDeletes` on Event.** Cancellation is a first-class transition with a
  reason (Event SPEC); a `deleted_at` column is not a lifecycle.
- **Seat capacity consumption formula (TKT-F-016).** `seats_per_user ×
  max_users_per_ticket` reserved at purchase time bakes "1 ticket = N gamers,
  each occupying a physical place" into pricing — exactly the assumption
  ADR-0005 exists to remove.
- **`SeatPlanResource` legacy wire shape (SET-F-016)** and the
  **JSONB→normalized converter (SET-F-017).** Compatibility scaffolding for a
  library and a migration that Konventix has neither of.
- **Confetti on seat-plan save (SET-F-019).** The Saving/Saved/error states
  survive; the animation is not a requirement.
- **`seats.custom_data` as a JSON blob.** The capability (per-resource
  attributes) survives as typed attributes declared by `ResourceType`; the
  schemaless blob does not (ADR-0009, lessons #11).

**Deferred post-release**

- **Agenda as a whole** — Phase 3 in `docs/ROADMAP.md`. PRG-F-001..007 are
  inventoried here so the Resources booking model can be designed knowing that
  agenda items will eventually want rooms (D-EVT-11), but no Agenda code ships
  in release scope.
- **Programme notification subscriptions (PRG-F-005)** and
  **`ProgramTimeSlotApproaching` delivery (PRG-F-004)** — follow Agenda.
- **Sponsor placement on programmes and slots (PRG-F-006)** — Sponsoring is
  post-release.
- **Venue images (EVT-F-007, VEN-F-002)** — a venue needs an address and geo
  coordinates to be useful; a gallery is presentation and can wait.
- **Webhook fan-out on `EventPublished` (EVT-F-005)** — Extensibility is
  post-release by decision; do not publish external contracts before internal
  ones are stable.
- **The whole LAN-directory surface** — LPPS event columns (EVT-F-013),
  organisation keys (ORG-F-006), the `/.well-known/lan-party.json` document
  (PUB-F-001..008). These are the clearest LAN baked-in assumptions in the Event
  domain and they belong to the LAN plugin (D-EVT-12).

## Open decisions

Twelve decisions, numbered `D-EVT-nn` as one series across all four modules.
Each is genuinely open: none is settled by an existing ADR, and each has at
least two options a reasonable person would pick differently.

### D-EVT-01: What is a resource booking's time dimension — intervals, slots, or whole-event?

**Context:** LanCore's `SeatAssignment` has no time at all. Konventix's
Resources SPEC says a booking "always occupies an interval, even when that
interval happens to be *the whole event*", and lists interval granularity as an
open question. The choice determines the schema, the conflict check, and how
ugly the LAN case looks.

**Option A — Half-open interval `[starts_at, ends_at)` on every booking.**
One nullable-free pair of timestamps; the whole-event case is written as the
event's own start and end. Pros: one code path; a shift, an agenda item and a
seat are checked by the same predicate; rescheduling an event is a bulk update
with well-defined semantics. Cons: every seat booking stores two redundant
timestamps that must be rewritten when `EventRescheduled` fires, and "is this
seat free" becomes a range query instead of a row lookup.

**Option B — Fixed slot grid per `ResourceType`.** The type declares a slot
length (30 min, 1 day, whole event); bookings reference slot rows. Pros:
conflict check is a unique index on `(resource_id, slot_id)`, works on SQLite,
trivially fast; capacity per slot is a simple counter. Cons: a materialised
slot table per resource per event is a lot of rows; changing the grid
invalidates bookings; "borrow this headset from 14:10 to 15:40" does not fit a
grid without lying about the times.

**Option C — Two modes on `ResourceType`: `whole_event` or `interval`.**
Whole-event bookings store no times; interval bookings do. Pros: seats stay
exactly as cheap as LanCore; no redundant timestamps. Cons: two code paths, two
conflict checks, and the SPEC's core promise ("never a separate path") is
broken on day one — the thing we are explicitly trying not to do.

**Recommendation: A**, with a `booking_granularity` on `ResourceType` that
constrains *input* (round to 15 min, or force the event's full span) without
changing storage. Accept the redundant timestamps; make `EventRescheduled`
shift whole-event bookings as an explicit, tested Action rather than pretending
it is free.

### D-EVT-02: How is the no-overlap invariant enforced, given the test suite runs on SQLite?

**Context:** Resources' SPEC calls conflict prevention "the module's core
invariant… enforced in the persistence layer, not in a controller". PostgreSQL
is production and offers `EXCLUDE USING gist` over a `tstzrange`. SQLite, the
current test database, has neither ranges nor exclusion constraints. LanCore
got away with a plain unique index because it had no intervals.

**Option A — Exclusion constraint in PostgreSQL; switch the test database to
PostgreSQL.** Pros: the invariant is real, unconditional, and cannot be bypassed
by a future code path; one implementation. Cons: CI needs a Postgres service;
test suite gets slower (seconds per run, not minutes); local developer setup
gains a dependency; migrations become Postgres-specific and the project quietly
stops being portable to MySQL.

**Option B — Application-level check inside the Action, under a pessimistic
lock (`SELECT … FOR UPDATE` on the resource row).** Pros: database-agnostic;
SQLite tests keep working; the failure message is a domain error, not a
constraint violation to translate. Cons: it is a controller-level guarantee
wearing an Action's clothes — a raw insert, a data fix, or a second write path
breaks it silently. This is exactly the class of bug the SPEC says it wants
prevented at the persistence layer.

**Option C — Both: exclusion constraint in production migrations (guarded by
driver), application lock everywhere.** Pros: real invariant where it matters,
tests still run on SQLite. Cons: two implementations of one rule that must
agree; the production-only constraint is never exercised by the test suite, so
the first time it fires is in production.

**Recommendation: A.** The invariant is the module's reason to exist, and
Konventix already names PostgreSQL as the deployment database in
`docs/architecture/overview.md`. Pay the CI cost once. If that is rejected,
take C but add a migration test that runs against PostgreSQL in CI only, so the
constraint is at least executed somewhere.

### D-EVT-03: Is a resource with capacity greater than one, one row or many?

**Context:** ADR-0005's exhibitor example books "booth B12, 12 m²" — a quantity
against a named thing. A workshop room for 40 is the same shape. A seat is
capacity 1. The Resources SPEC lists this as open, and notes a capacity column
complicates the overlap invariant.

**Option A — Always one row per bookable unit.** Forty rows for a room of
forty. Pros: the overlap invariant stays "at most one booking per resource per
interval", which is the simplest possible rule and the one an exclusion
constraint expresses natively; every booking points at an identifiable unit.
Cons: absurd for square metres; the forty rows are indistinguishable, so the
picker has to hide them, and "book any 5 of these" needs allocation logic
anyway.

**Option B — `capacity` on the resource; conflict check is a sum over
overlapping bookings.** Pros: one row for a room, one for a booth strip;
matches how people talk about it. Cons: the invariant becomes an aggregate
check, which no single database constraint expresses — it needs a lock plus a
computed check, weakening D-EVT-02 for exactly the types that use it.

**Option C — `allocation_mode` on `ResourceType`: `unit` (capacity 1, one row
per unit, individually pickable) or `pooled` (capacity N, quantity-bearing
bookings).** Pros: seats, rooms and booths each get the model that fits;
"pickable" and "countable" are genuinely different user experiences, so the
split is visible to users anyway. Cons: two conflict checks again; the unit
model gets the DB constraint and the pooled model does not.

**Recommendation: C**, with the honest consequence written down: pooled
resources get a weaker guarantee than unit resources. Seats, rooms with a fixed
identity, and individually numbered devices are `unit`. Square metres and
undifferentiated capacity are `pooled`. Do not let a pooled type be spatially
plotted — if you can click it on a map, it is a unit.

### D-EVT-04: Is Venue an Event sub-concept or its own entity?

**Context:** `module-map.md` says Event absorbs Venue and the Event SPEC says
Venue is "deliberately demoted to a sub-concept". But LanCore's Venue is
brand-level and reusable, Resources references `venue_id` as a plain ULID, and
the Event SPEC's own open questions ask about multi-venue events and
venue-less online events.

**Option A — Venue is a value object on the Event.** Address and geo live on
the `events` table. Pros: simplest possible; no second entity, no reuse
bookkeeping; an online event just has null columns. Cons: the operator retypes
the same hall's address every year; Resources has nothing stable to hang a
reusable resource catalogue on (see the Resources SPEC's last open question);
a multi-venue event is unrepresentable.

**Option B — Venue is a brand-owned entity inside the Event module; events
reference it.** This is what LanCore does and what the current SPEC implies.
Pros: reuse across a brand's events; one place to fix an address; Resources'
`venue_id` reference is meaningful; online events reference no venue. Cons: an
entity with its own CRUD, policy and capabilities living inside a module that
declares restraint as its design goal; "Event owns Venue" starts to feel like a
label rather than a boundary.

**Option C — Venue is its own module (or moves to Resources).** Pros: honest
about it being a physical-world catalogue that Resources cares about more than
Event does; a future room/facility catalogue lands naturally next to it.
Cons: contradicts `module-map.md`, which is settled; adds a module for three
tables; Event still needs the venue's city for display and would keep a read
model of it — more machinery than the problem deserves.

**Recommendation: B**, with two amendments to the Event SPEC: make the
event↔venue link a many-to-many (`event_venues`) from the start rather than a
nullable FK, so multi-venue and online (zero venues) are the same mechanism;
and state explicitly that a venue is brand-scoped, not event-scoped, because
that is what makes D-EVT-05's resource catalogue question answerable later.

### D-EVT-05: Where does "event capacity" live, given Quota is Registration's and physical space is Resources'?

**Context:** LanCore has `events.seat_capacity` plus a consumption formula in
Ticketing (TKT-F-016). The Event SPEC calls capacity "the recurring temptation"
and refuses it. But somebody has to answer "is the event full?", and today
three plausible owners exist.

**Option A — No event-level capacity at all.** Registration owns `Quota`
(how many may register, per type or shared); Resources owns inventory (how many
units exist). Event stores nothing. Pros: each number is owned by the module
that can compute it correctly; ADR-0005 already made Quota standalone; nothing
drifts. Cons: no single answer to "how full are we"; the admin dashboard must
compose two modules' numbers, and an operator who just wants "max 300 people"
has to express it as a shared quota.

**Option B — An advisory `Event.capacity` display field.** A number the
organiser types, shown publicly, enforced by nothing. Pros: satisfies the
"how many fit" question for marketing and the LAN directory; costs one column.
Cons: it will be mistaken for an enforced limit within a week; it drifts from
both quota and inventory; it is precisely the field LanCore had.

**Option C — Derive capacity from resource inventory.** "Event capacity" =
count of bookable person-type resources. Pros: always true; no drift. Cons:
only meaningful for events whose limit *is* physical, and Resources is a
Pennant-gated module — a brand with the feature off would have capacity zero,
which is nonsense.

**Recommendation: A**, and delete `seat_capacity` rather than porting it. Add a
composed read-only "capacity summary" to the event dashboard, assembled from
Registration's quota consumption and Resources' inventory as two separately
labelled numbers, because they genuinely answer different questions and showing
them merged is what created the confusion in the first place.

### D-EVT-06: When a registration grants a resource claim, who picks the concrete resource, and when?

**Context:** Registration's `grants` carry resource claims (ADR-0005);
Resources fulfils them. LanCore's behaviour is: buying a ticket gives you the
right to pick, and you pick later, yourself, from a canvas. A conference room
booking and an exhibitor booth are assigned by an organiser. A device loan is
handed out at a counter.

**Option A — Claim creates an unassigned entitlement; the holder self-picks
later.** LanCore's model. Pros: participants love picking their seat; no
organiser work; the pick is a cheap, revocable act. Cons: an entitlement that
is not yet a booking is a fourth state to track and to expire; the "how many
are still unplaced" question needs its own query.

**Option B — Auto-assign at confirmation.** The system picks a free resource
when the registration confirms. Pros: no unassigned state; inventory and
bookings always agree; works for booths and devices where nobody expects a
choice. Cons: takes away the feature LAN participants care most about; a bad
auto-pick generates support work; group-sits-together is impossible.

**Option C — `allocation` on `ResourceType`: `self_select` / `auto` /
`admin_assign`.** Pros: each type behaves the way its users expect; the
unassigned-entitlement state exists only for `self_select`. Cons: three flows
to build and test; the entitlement-vs-booking distinction still has to exist
for one of them.

**Recommendation: C.** Note the consequence for the SPEC: an unfulfilled claim
is *not* a `ResourceBooking` in `held` state — `held` means a concrete resource
is reserved with an expiry. An unallocated `self_select` claim has no resource
yet and therefore needs either its own lightweight row in Resources or must be
read from Registration's grants on demand. Decide that when building, but do
not let it default into a fake booking with a null `resource_id`.

### D-EVT-07: How does "this block is only for VIP tickets" survive ADR-0004?

**Context:** SET-F-011 stores `ticket_category_id` values in a Seating pivot —
Resources holding a foreign module's entity as a rule. Konventix forbids
importing another module's models, but ULIDs as plain values are allowed, so
this is a modelling choice, not a hard block.

**Option A — Opaque eligibility tags.** `ResourceGroup` carries a set of string
tags (`vip`, `crew`, `exhibitor`); Registration's grant carries the tags it
satisfies; Resources matches strings. Pros: zero knowledge of Registration's
schema; the same mechanism works for admissions, shifts and organisers as
holders; tags survive a RegistrationType being renamed or replaced. Cons:
strings are unvalidated — a typo silently makes a block unbookable, and there
is no referential integrity or "which types can use this block?" report without
asking Registration.

**Option B — `registration_type_id` allowlist as plain ULIDs.** Same shape as
LanCore, but no Eloquent relation. Pros: exact and enforceable at the moment of
booking; the admin UI can list real type names by asking Registration for
display data. Cons: Resources now depends on Registration's *identity* schema
and needs a read model of type names to render its own admin screen; deleting a
type leaves orphan rows; it is coupling with the relation removed rather than
coupling removed.

**Option C — Registration resolves eligibility and passes a concrete
`resource_group_id` in the grant.** Resources enforces nothing. Pros: the
module that knows about types decides; Resources stays purely mechanical.
Cons: pushes physical-layout knowledge into Registration (it must know which
groups exist); a direct booking not originating from a claim — an organiser
seating a walk-in — has no eligibility check at all.

**Recommendation: A**, with the typo risk mitigated rather than ignored: tags
are per-brand rows in Resources (a small `eligibility_tag` table with a label),
so the admin picks from a list on both sides, and Registration references the
tag by ULID in its grant definition. That keeps the string semantics for
matching while giving the UI something to validate against.

### D-EVT-08: What holds a booking — the registration, the admission, or anything?

**Context:** LanCore keys assignments by `(ticket_id, user_id)`. ADR-0005 makes
these two different things: a booth belongs to the company's Registration, a
seat to a person's Admission, and Resources' SPEC already lists shifts and
organisers as possible holders.

**Option A — Always the Admission (a person).** Pros: one holder type; check-in
and badge printing can show "your seat" trivially; GDPR export is a single
join. Cons: an exhibitor's 12 m² of booth has no natural person, so it has to
be pinned to an arbitrary crew admission; a booking made before admissions are
issued is impossible, which breaks invoice-settled exhibitors.

**Option B — Always the Registration (the contract).** Pros: everything is
bookable at confirmation time, before any person is named; matches how money
and contracts work. Cons: "which seat is mine" becomes a lookup through the
registration and a per-person disambiguation that the model does not store —
exactly the `1 ticket = 1 gamer` problem in a new place.

**Option C — `holder_type` discriminator (registration / admission / shift /
organizer), with `ResourceType` declaring which kinds it accepts.** Pros:
expresses all four ADR-0005 cases directly; a room booked by a shift
(D-EVT-11) becomes possible without a special case. Cons: every consumer must
handle four holder kinds; GDPR deletion must fan out per kind; a booking's
"owner" for display is resolved differently each time.

**Recommendation: C**, with `ResourceType.allowed_holder_types` as a
non-nullable list so a seat type cannot accidentally be booked by a shift and a
booth type cannot be booked by an admission. The cost is real: Resources'
`src/Gdpr/` handler has to know that only `admission` and `organizer` holders
carry personal data.

### D-EVT-09: Does accepting a crew shift imply an access right?

**Context:** Staffing's SPEC lists this as an open question and ADR-0005 draws
the line hard: Admission is a person's access right and it comes from a
confirmed Registration. But a volunteer who signs up for Saturday night
expects to get in on Saturday night, and forcing a small team through a crew
registration flow is the kind of friction that sends people back to a
spreadsheet — which is the exact thing Staffing exists to prevent.

**Option A — No coupling. A crew Registration must exist first; only then can
the person be rostered.** Pros: ADR-0005 stays clean; one path issues
admissions; the crew person appears in registration counts, quota and GDPR
exports like everyone else. Cons: a manual step per volunteer; leads will roster
people who then cannot get in, and the mismatch surfaces at the entrance.
Staffing's own `Consumes` table already assumes this direction — eligibility is
read from `AdmissionIssued`.

**Option B — Staffing emits `AssignmentAccepted`; Registration optionally
auto-creates a crew registration under a configured type.** A per-brand setting
names the RegistrationType to use. Pros: one click for the volunteer; the
admission is still issued by the module that owns admissions, so no boundary is
crossed; the auto-created registration is a normal registration with a normal
audit trail. Cons: a new implicit path into Registration that operators must
configure; if the type has `approval: required`, acceptance silently produces a
pending registration and the volunteer still cannot get in.

**Option C — Staffing issues Admissions directly for rostered staffers.**
Pros: fewest moving parts. Cons: breaks ADR-0005 outright — two modules issuing
access rights, two check-in stories, and crew invisible to Registration's
quota and reporting. Do not.

**Recommendation: B**, with the failure mode made explicit rather than silent:
if the configured crew type requires approval, `AssignmentAccepted` produces a
pending registration *and* Staffing shows the assignment as "accepted, access
pending" until `AdmissionIssued` arrives. The coupling is an event in one
direction only; Staffing never writes to Registration.

### D-EVT-10: How are teams structured, and is standing membership a thing?

**Context:** LanCore has exactly two levels (OrgaTeam → OrgaSubTeam), a
team-level organizer plus ordered deputies, a sub-team leader, and a
`(Deputy|Member)` role on membership. Staffing's SPEC asks whether teams nest.
Behind that sits a second question the SPEC does not ask: once shifts and
assignments exist, is standing membership still needed, or is a person "on the
team" precisely when they have an assignment?

**Option A — Flat teams per event; membership derived from assignments.**
Pros: minimal schema; no roster to keep in sync with reality; coverage is the
only status that matters. Cons: loses the org chart entirely (OT-F-007 is the
one visible product LanCore shipped here); a team lead cannot see "my people"
before publishing any shift; "who is available to ask" has no answer.

**Option B — Flat teams with a standing `TeamMembership` pool; assignments
reference shifts, not teams.** Pros: keeps the roster and the public crew page;
membership is the pool a lead offers shifts from; two levels of concept, no
nesting. Cons: a large event's "Tech" team wants sub-groups, and tags are a
weaker answer than structure when a sub-group has its own lead.

**Option C — `Team.parent_id` self-reference plus standing membership.**
Pros: covers LanCore's two levels and anything deeper; one table; a sub-team
lead is just a membership with `role: lead`. Cons: unbounded depth invites a
five-level tree nobody can navigate; queries ("all people under Tech") become
recursive; the public org chart needs a depth cap anyway.

**Recommendation: C with a depth cap of two enforced in the Action**, and
standing membership kept as a first-class table. Membership is the pool,
assignment is the work — LanCore's `(Deputy|Member)` enum splits into
`TeamMembership.role` (`lead` / `deputy` / `member`, permission-neutral per
OT-F-010) and `ShiftRole` (the function during one shift, with a headcount
demand). That split is what turns an org chart into a workforce.

### D-EVT-11: Do agenda items and shifts create real resource bookings?

**Context:** Three modules will own time-boxed things that happen in a room:
`ResourceBooking`, `AgendaItem`, and `Shift`. Agenda's SPEC says an item
"points at a resource by ULID"; Staffing's says a shift "references a resource
by ULID". Neither says a booking is created. If neither books, two talks can be
scheduled in the same room and the no-overlap invariant protects nothing that
matters.

**Option A — Plain references, no bookings.** Pros: no cross-module writes;
Agenda and Staffing stay independent of whether Resources is even active for
the brand (both are gateable). Cons: double-booking is possible and invisible;
the generalisation of Seat into Resource buys nothing for the two modules that
most needed it; conflict detection has to be reimplemented per module.

**Option B — Agenda and Staffing create real bookings with
`holder_type: agenda_item` / `shift`.** Pros: one conflict check for the whole
system; "what is in Hall B on Saturday" is one query; this is the payoff for
D-EVT-08's discriminator. Cons: two more modules depend on Resources being
active; when the Pennant feature is off they must degrade to a plain location
label (Agenda's SPEC already anticipates this) and the degradation is two code
paths; booking creation is a cross-module write that must go through an event,
not a direct call.

**Option C — Only Agenda books; shifts stay references.** Pros: rooms are
protected where clashes are most visible; Staffing stays simple. Cons:
arbitrary — a shift that occupies the entrance desk clashes with an agenda item
at the entrance desk just as badly.

**Recommendation: B**, with the mechanism spelled out: Agenda and Staffing do
not write `ResourceBooking` rows. They emit `AgendaItemScheduled` /
`ShiftPublished` carrying `resource_id` and the interval; Resources listens and
creates a booking, and emits a rejection event on conflict which the source
module surfaces as a validation failure. Slower and more awkward than a direct
call — and the only version that respects ADR-0004.

### D-EVT-12: Where do LAN-specific event attributes live?

**Context:** EVT-F-013 puts nine LAN-directory columns on `events` (showers,
sleeping/alcohol/smoking/age/food policies as bitsets, three network speed
fields, attendance mode, syndication status), ORG-F-006 adds four more to
organisation settings, and PUB-F-001..008 publishes them. A conference has none
of these; a trade fair has different ones (loading dock hours, rigging points).
Konventix is event-agnostic, so this is the sharpest test of the plugin
architecture in the Event family.

**Option A — Keep them as columns on `Event`.** Pros: zero indirection; the
admin form is one page; migrations are trivial. Cons: the kernel Event module
carries LAN vocabulary forever, and the next format (conference, trade fair)
adds its own columns until `events` is a union of every event type anyone ever
ran. This is the LanCore outcome.

**Option B — A LAN plugin owns `EventLanProfile`, keyed by `event_id`, and
registers its own admin form section and its own publishing endpoint.**
Pros: Event stays clean; the LAN directory feed lives with the LAN plugin;
proves the plugin architecture on real data rather than on GameOps alone; a
brand that runs conferences never sees the fields. Cons: a second table and a
second form section per event; the plugin needs a UI slot on the event edit
page (Platform's slot registry already provides one); cross-cutting queries
("show all events with showers") need the plugin.

**Option C — A generic per-brand "event attribute set": operator-defined typed
fields attached to events.** Pros: any format, no code; conferences and trade
fairs get theirs without a plugin. Cons: it is a schemaless blob with a schema
editor bolted on — a slower path back to lessons-from-lancore #11; validation,
TypeScript types (ADR-0009) and the publishing feed all become runtime-dynamic;
and the LAN directory needs *specific* field names anyway, which user-defined
fields cannot guarantee.

**Recommendation: B.** Put the LAN profile and the LPPS publisher in the
GameOps module (already "the explicitly LAN-specific plugin" per
`module-map.md`), or in a sibling LAN plugin if GameOps' scope should stay
server orchestration. Either way, post-release: nothing in release scope
depends on the directory feed, and building it first would be building the
plugin extension surface before the modules it extends are stable.

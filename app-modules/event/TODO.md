# Event — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next

(nothing outstanding — Slice 3 closed this section; see Done)

## Backlog

- [ ] A calendar entry per event day or per session, once Agenda exists
      (design 2026-09-11 §12): today's `.ics` is one `VEVENT` for the whole
      event, because an event has no sub-sessions yet.
- [ ] The console event source (`registerConsoleEventSources`) is
      unbounded — every unarchived event across every brand on every admin
      response. Bounded in practice by `archived_at`; add a limit or an
      `Inertia::optional()` wrapper if an operator ever runs hundreds.

- [ ] Reschedule flow in the admin UI calls `UpdateEventDetails`, which
      emits `EventRescheduled` on any schedule change regardless of
      lifecycle state — `RescheduleEvent` (which guards on Published) is now
      only reachable programmatically. Decide whether post-publish schedule
      edits should be forced through `RescheduleEvent`'s guard in the
      controller.
- [ ] `src/Gdpr/` handler for organiser contact details — implemented even when
      it reports no subject data, so Compliance's fan-out is complete by
      construction.

## Done

- [x] Legacy slug for the cutover (#309). `events.legacy_slug`, nullable,
      unique per brand; `Actions\Concerns\GuardsEventSlugs` makes `slug` and
      `legacy_slug` one namespace per brand (a legacy slug never equals any
      event's slug, its own included, nor another's legacy slug, and a slug
      never equals another event's legacy slug). `GET /events/{slug}` for a
      slug nobody carries 301s onto the canonical URL through the route's
      `missing()` hook (query string kept, hidden events still 404). Edit
      form field "Legacy slug (redirect)". Only the landing route; the old
      system's other paths are not redirected. Case-insensitive: the legacy
      slug is stored lowercase (`EventData::prepareForPipeline()`) and the
      redirect lowercases the incoming segment, so `/events/SXLAN-2026`
      301s too; the canonical binding stays exact.

- [x] The live hub's per-event half (0.9.0 sub-project A, design 2026-09-22,
      tasks 1, 11 and 12). `event_days.day_ends_at` (`HH:MM`, nullable,
      default 00:00 of the next day) in the days editor and on
      `EventDaysChanged` as an additive key, so Admission can close a
      once-per-day access window at 03:00 rather than at midnight.
      `event_hub_layouts` + `Models\EventHubLayout` +
      `Data\HubLayoutEntryData`/`UpdateHubLayoutData`, written whole by
      `Actions\SaveEventHubLayout`, which emits `EventHubLayoutChanged` with
      the complete layout. Admin page `admin/events/{event}/hub` behind
      `Permission::UpdateEvent` — a sortable list of every card and link
      Platform's `HubCatalogue` reports, on/off toggles and the "Startseite
      wird zum Hub" switch — plus the "Live hub" link on the event
      dashboard. Event stores Platform's registry keys and never Platform's
      objects; unknown keys in a stale layout are the hub page's problem to
      drop, not this module's to validate (ADR-0004).
- [x] `CountdownBlock` links the event name (bugfix round 2026-09-21,
      Task 17b): `CountdownResolvedData.slug` under the hero's rule —
      set only when `isVisibleOnLanding()`, so a draft's countdown never
      links to a landing page that 404s; `CountdownBlock.vue` links through
      Wayfinder's `event.show`. `BlockTypesTest`.
- [x] The per-event attendee-list switch (bugfix round 2026-09-21, Task
      15, ADR-0064): `events.attendee_list_visibility`
      (`Enums\AttendeeListVisibility`, `off`/`members`/`public`, default
      off) in the event editor beside the re-entry policy, and
      `Events\EventAttendeeListPolicyChanged` emitted at creation and on
      every change with the complete current value — the shape of
      `EventReentryPolicyChanged`, for the same ADR-0004 reason.
      `event:replay-details` does not re-fire it; Registration's summary
      column was backfilled once by its own migration instead.
- [x] The event hero links to the event (2026-09-21 bugfix round, task 8):
      `Data\Blocks\EventHeroResolvedData` gained `slug`, set only when the
      event `isVisibleOnLanding()` (null when not found, cross-brand, or a
      draft — the landing controller would 404 on it), and
      `components/blocks/EventHeroBlock.vue` renders the whole hero as one
      `<Link>` to `event.show` named by its heading when the slug is set,
      the plain `<section>` otherwise. Admission's ticket event band does
      the same with its `eventSlug`. Pinned in `tests/BlockTypesTest.php`.
- [x] The event banner (#292): `events.banner_media_id`, a media-library
      ULID owned by the event's brand, picked in the editor (upload from the
      picker included), usage recorded and released like a brand's marks,
      announced on `EventCreated` and `EventDetailsUpdated` and re-fired by
      `event:replay-details` so read models backfill.

- [x] Public calendar download (#72, ADR-0061): `GET
      /events/{event:slug}/calendar.ics` calls Platform's
      `Calendar\CalendarFile::forEvent(...)` with this module's own scalars.
      `event:replay-details` gained a venue pass — it now also re-fires
      `VenueAssigned` for each event's primary venue — so a backfilled
      calendar file and ticket event band are never silently missing their
      venue on an event created before this deploy.
- [x] `event:replay-details {--event=}` re-emits `EventRescheduled` and
      `EventDetailsUpdated` with each event's current values, schedule
      first, so a read model that came later than the event (Resources'
      `resource_event_windows`) can be backfilled from the contract.
      `EventRescheduled` gained a trailing nullable `brandId` for the same
      reason: a projection with no row for the event yet has no other way
      to learn which brand to insert under (Seating S0, #S0).
      Since 0.9.0 it also re-emits `EventDaysChanged` with the event's
      current `event_days` rows, unflagged like the three around it because
      it carries a complete current set rather than a transition — that is
      what fills Admission's `entrance_event_profiles.days` and Agenda's
      `agenda_event_summaries.days` for events older than either read model
      — and `--with-state`, which re-emits the one lifecycle event the event
      is in now (Agenda's public programme hangs off that state).

- [x] The events list defaults its brand filter from the console selection,
      cleared per list with `brand=all`. Only the brand half applies: this
      list is what the console's event selector points into.

- [x] Event contributes the console's event selector. The options come from
      this module's own provider through Platform's `ConsoleEventSources`
      registry — the same unconditional boot-time pattern the block types
      use — so Platform can offer an event selector while importing nothing
      from here (ADR-0004). Unarchived events only, since archived means put
      away, newest first so the event somebody is working on is at the top,
      and narrowed with `forBrand()` when the operator has picked a brand.

- [x] A public event list at `/events` (`event.index`), and the topbar entry
      that points at it. Until this landed a brand's events were reachable
      only by knowing a slug. Its rule is `Event::scopeListedPublicly()`,
      deliberately not `isVisibleOnLanding()`: unlisted means reachable by
      link and *not* enumerated, archived means put away, and cancelled
      stays listed because someone planned around it. Upcoming and past are
      split in PHP over `endsAtInstant()` — the stored values are wall-clock
      (ADR-0027), so SQL cannot do it.

- [x] The landing page has a call to action. It is not Event's: Registration
      contributes its offers through the new `event.landing` slot, so this
      module still imports nothing from it, and a gateable module can join
      the same page later without Event learning it exists.

- [x] Slice 3 — Event completion. Multi-venue (D-EVT-15): `event_venues`
      M:N with primary flag via the `EventVenue` ULID pivot replaces
      `events.venue_id` (existing assignments migrated as primary);
      `AssignVenue`/`UnassignVenue` emit `VenueAssigned` (with `isPrimary`)
      / `VenueUnassigned`, exactly one primary enforced by demotion. Event
      days (D-ADM-04): `EventDay` entity (date, 1-based sequence, label),
      managed only through `SyncEventDays` — day ULIDs stay stable for kept
      dates (Admission references them), full-set `EventDaysChanged` on
      every change. Re-entry knob (D-ADM-03, `ReentryPolicy`, default
      unlimited) and `predecessor_event_id` (D-EVT-14) on the event.
      The knob was stored but **published nowhere** until Slice 5a added
      `EventReentryPolicyChanged` (emitted at creation and on change, full
      current value): ADR-0033 needs it decidable at a door with no network,
      and Admission may not read this module's models (ADR-0004).
      New Actions + events: `CreateEvent` (explicit brand, per-brand slug
      guard) → `EventCreated`; `UpdateEventDetails` → `EventDetailsUpdated`
      (+ `EventRescheduled` when the schedule moved); `AnnounceEvent` →
      `EventAnnounced`; `StartEvent` → `EventStarted` (Running stays an
      explicit operator transition, not clock-derived — settled). Admin
      CRUD: events index on the data-table pattern + create dialog, Edit
      page (details, day editor, venue assignment), per-state lifecycle
      buttons on the dashboard (server sends only legal transitions),
      Venues index/create/edit + nav entry. Public landing route now
      renders `event/Landing` via Inertia (module-local pages, Slice 1)
      with days and venues. `EventData` gained visibility/reentry/
      predecessor and `#[TypeScript]`; slug uniqueness enforced per brand
      in the Actions. `tests/EventManagementTest.php`.
- [x] Event model and migration: ULID primary key (ADR-0014), `BelongsToBrand`
      (ADR-0010), wall-clock `starts_at`/`ends_at` plus IANA `timezone`
      (ADR-0027), slug unique per brand, lifecycle `state` enum, `visibility`,
      `published_at`. Factory included, wired both directions.
- [x] Venue model and migration: brand-owned, reusable, postal address, geo
      lat/lng (nullable), access notes, opening times as a typed structure
      (`OpeningPeriodData` via `OpeningTimesCast`, not a raw JSON blob).
      Assignable to an event via nullable `Event::venue_id`. Factory
      included, wired both directions.
- [x] Lifecycle transitions as Actions with `DB::transaction` inside —
      `PublishEvent`, `UnpublishEvent`, `RescheduleEvent`, `CancelEvent`,
      `ConcludeEvent`, `ArchiveEvent` — each guarding legal starting states
      and emitting its contract event from SPEC.md's table. No status writes
      from controllers.
- [x] `Data` objects for the event and venue forms (`EventData`, `VenueData`,
      `OpeningPeriodData`) — single source for validation rules (ADR-0009).
      TypeScript generation not wired yet (see Next).
- [x] Public event landing route (`GET /events/{event:slug}`), brand-scoped
      via `BelongsToBrand`'s global scope on route-model binding. Returns
      JSON, not an Inertia render — see Next for why.
- [x] `src/Enums/Permission.php` capability catalogue (`ViewEvents`,
      `CreateEvent`, `UpdateEvent`, `PublishEvent`, `RescheduleEvent`,
      `CancelEvent`, `ArchiveEvent`, `ViewVenues`, `ManageVenues`).
- [x] `event-hero` and `countdown` static-page block types
      (`Blocks\EventHeroBlock`/`CountdownBlock`), registered via the new
      `EventServiceProvider::registerBlockTypes()` hook — Platform's
      ADR-0003 cross-module proof: Platform's registry accepts them without
      ever `use`-ing an Event model. The stored block payload is just an
      event ULID (`Data\Blocks\EventHeroBlockData`/`CountdownBlockData`);
      both types implement Platform's new `ResolvesBlockProps` interface and
      look the event up with Event's own model, brand-scoped via
      `BelongsToBrand` (a stale or cross-brand ULID resolves to a graceful
      "not found" shape, not an error). `event-hero`'s resolved props
      (`Data\Blocks\EventHeroResolvedData`) are the wall-clock `starts_at`/
      `ends_at` plus `timezone` an organizer promised (ADR-0027) — printed
      as-is by the Vue component, never through `new Date(...)`.
      `countdown`'s resolved props (`Data\Blocks\CountdownResolvedData`)
      instead carry `startsAtInstant()`'s real ISO-8601 instant, since a
      ticking countdown needs an unambiguous point in time to diff against
      `now()`. Vue components live in host
      `resources/js/components/blocks/{EventHeroBlock,CountdownBlock}.vue`
      (module-local page/component resolution is still unwired — see
      "Wire module-local Inertia page resolution" above); `CountdownBlock`
      wraps a ported `resources/js/components/countdown/CountdownTimer.vue`
      (LanCore's, converted to semantic tokens) using `@vueuse/core`'s
      `useNow`.
- [x] Per-event admin dashboard skeleton (ADR-0029, ADR-0003 "slots are an
      activation gate point"): `EventAdminController::index()`/`show()`
      behind `event.view-events`, `GET /admin/events` and
      `GET /admin/events/{event}` (`routes/admin/event.php`). The dashboard
      composes Event's own header (name, wall-clock dates via
      `startsAtInstant()`/`endsAtInstant()`, timezone, state badge — ADR-0027)
      with contributions from `Konventix\Platform\Slots\SlotRegistry`'s
      `event.admin.dashboard` slot; Registration and Admission each register a
      card component name via the new `ModuleServiceProvider::registerSlots()`
      hook (mirrors `registerBlockTypes()`) and fetch their own numbers from
      their own admin endpoints — this controller never queries their models.
      `EventServiceProvider::registerNavigation()` adds the "Events" admin nav
      entry (icon `'calendar'`, permission `event.view-events`, order 15) —
      see Backlog for the `AdminLayout` icon-map gap. Vue pages at
      `resources/js/pages/admin/events/{Index,Dashboard}.vue` (host-level,
      same reason as the countdown/hero blocks above) and
      `resources/js/components/admin/events/StatCard.vue`.
      `tests/EventAdminTest.php` covers capability + operator-host
      enforcement, the cross-brand index listing, and that both modules'
      slot contributions appear on the dashboard.
- [x] `event:replay-details` now also re-fires `VenueAssigned` for each
      event's primary venue (design §13), after the schedule and the
      details, so a read model added after an event already has one — such
      as Admission's `admission_event_summaries` — can still backfill its
      location instead of shipping with a permanently-null `venue_label`.
- [x] Venues carry what a public page needs, and have one (bugfix round
      2026-09-21, Task 18). Migration
      `add_details_images_and_slug_to_venues_table`: a per-brand unique
      `slug` backfilled deterministically from the name (creation order
      decides who keeps the bare slug, the later one gets `-2`),
      `description`, `entry_latitude`/`entry_longitude`,
      `seat_plan_reference`, `public_transport`, `parking_spots`,
      `loading_zones`, `image_media_ids`, `primary_media_id`.
      `Data\VenuePlaceData` behind `Models\Casts\VenuePlacesCast` for the
      two place lists and `Models\Casts\MediaIdListCast` for the ordered
      media ULIDs (ADR-0035: ULIDs, no foreign key, usages tracked) — a
      JSON column only ever behind a typed cast, as `OpeningTimesCast`
      already was (ADR-0009). `Venue` enforces on every save that the
      primary picture is one of the venue's own, and
      `isPubliclyReachable()` answers the public route's visibility
      question through the new `Event::scopeVisibleOnLanding()`.
      `VenueData` is `#[TypeScript]` and is now the console's only venue
      form type; `CreateVenue`/`UpdateVenue` settle the slug, refuse a
      picture from another brand's library and keep the media library's
      usage rows in step (`Actions\Concerns\SavesVenueDetails`, modelled on
      `UpdateEventDetails`).
- [x] Console: `admin.venues.edit` (`VenueAdminController::edit()`) and
      `resources/js/pages/admin/venues/Edit.vue` with cards for Details,
      Description, Coordinates, Getting there, Parking spots, Loading
      zones, Seat plan reference and Images —
      `components/admin/VenueImageList.vue` (one `MediaPicker` per slot
      plus an empty one, move up/down, a "Primary" radio) and one
      `components/admin/VenuePlacesFields.vue` reused by both place lists.
      The index's row action is a real `<Link>` to that page (keyboard
      reachability, .ai/rules/js.md); the create dialog stays and now lands
      on the edit page. Unsaved-changes guard per ADR-0038.
- [x] Public: `venue.show` (`GET /venues/{venue:slug}`),
      `VenueLandingController`, `pages/event/Venue.vue` and a hand-written
      `components/VenueCarousel.vue` — `aria-roledescription`, labelled
      slides, named prev/next buttons and dots, arrow/Home/End keys, an
      `aria-live` status, reduced motion honoured in CSS, and a single
      picture rendered as a picture with no controls (ADR-0055). Its
      arithmetic is pure in `resources/js/lib/carousel.ts` with a vitest
      beside it, as is the image list's in `lib/venueForm.ts`.
- [x] The venue edit page has an "Opening times" card (round 4, Task 28):
      `components/admin/VenueOpeningTimesFields.vue` — repeatable rows on
      the existing `Data\OpeningPeriodData` / `Models\Casts\OpeningTimesCast`
      with a weekday select, `TimeField` for open and close (a wall clock on
      the site's own door, so deliberately not `zoned`), add/remove and a
      per-row error (`openingTimes.0.opensAt`). A list rather than a
      seven-row grid, so a site that closes for lunch is two rows on one
      day. `lib/venueForm.ts` gained `WEEKDAYS` and `emptyOpeningPeriod()`
      (next day, previous row's times, no wrap past Sunday) with vitest
      beside it; `VenueAdminTest` round-trips a set, pins the per-row error
      and pins that an empty list clears. Nothing else on the page changed
      in behaviour — the Details card's fields moved into
      `components/admin/VenueAddressFields.vue` and the three textareas into
      `components/admin/VenueTextArea.vue` only to keep `Edit.vue` under the
      ~300-line mark it had already passed.

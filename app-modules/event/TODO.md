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

# Event — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

## Next

- [ ] `AnnounceEvent` (Draft → Announced) and `StartEvent` (Published →
      Running) Actions. Neither exists yet: `PublishEvent` currently accepts
      `Draft` directly, and `Running` is only reachable today via the
      factory's `running()` state or a direct write. `ConcludeEvent` guards
      on `Running`, so nothing can legally reach it through the Action layer
      alone until `StartEvent` lands — worth checking whether `Running` should
      instead be a computed/derived state (now between `starts_at`/`ends_at`)
      rather than a stored, manually-triggered transition.
- [ ] `CreateEvent` action and the `EventCreated`/`EventDetailsUpdated`
      contract events from SPEC.md's table — out of scope for this change,
      which only covers the six lifecycle-transition Actions
      (publish/unpublish/reschedule/cancel/conclude/archive).
- [ ] `AssignVenue` action and the `VenueCreated`/`VenueUpdated`/
      `VenueAssigned` contract events from SPEC.md's table — Venue and Event
      models/factories exist and `Event::venue_id` is assignable directly,
      but no Action emits these yet.
- [ ] Render the public landing route (`GET /events/{event:slug}`) as an
      Inertia page instead of JSON — module-local page resolution is now
      wired (Slice 1: host + `app-modules/*/resources/js/pages` merge, see
      Platform's TODO Done entry), so the blocker is gone; the page itself
      is Slice 3 scope.
- [ ] TypeScript generation for `EventData`/`VenueData`/`OpeningPeriodData`
      (spatie/typescript-transformer, ADR-0009) — another workstream owns the
      generation pipeline; the Data classes exist and are ready for it.
- [ ] `EventData::rules()`/`VenueData` do not yet validate `slug` uniqueness
      per brand (`Rule::unique('events','slug')->where('brand_id', ...)`) —
      needs the current brand from `BrandContext`, which a static `rules()`
      method can resolve via the container, but wasn't added since no
      controller consumes these Data objects for writes yet.

## Backlog

- [ ] Multi-venue events (decision log Round 6, D-EVT-15, user override — new
      release scope): replace the nullable `Event::venue_id` with a
      many-to-many `event_venues` link carrying a "primary" flag, per the
      inventory's D-EVT-04 recommendation B. Zero venues (online-only) is the
      empty-list case; `VenueAssigned`'s payload needs to say whether it is
      the primary venue.
- [ ] `predecessor_event_id` ULID reference (decision log Round 6, D-EVT-14,
      user override — new release scope): nullable column on `events`, no DB
      foreign key (ADR-0014), populated at creation and editable afterward.
      Registration and Community are the modules expected to read it first.
- [ ] Event-day concept (decision log Round 6, D-ADM-04, user override — new
      release scope, paired with Admission's day-scoped validity): model
      per-event days as a first-class entity Event owns (date, sequence,
      label), so Admission can validate an admission against a specific day
      rather than only a `valid_from`/`valid_until` pair. Emit an event when
      an event's day set changes so Admission's validity evaluation can react.
- [ ] Re-entry policy knob (decision log Round 6, D-ADM-03): add the
      once-only / once-per-day / unlimited setting to the Event model — the
      event carries this policy, checkpoints stay policy-free. Default
      unlimited.
- [ ] Policies wiring for `src/Enums/Permission.php` (the enum itself is
      done — see Done below) — no Action or controller checks it yet, and
      `permissions:sync` does not exist in the codebase yet either.
- [ ] `EventAdminController::index()` lists every event across every brand
      with no pagination/search/sort — fine for the dashboard skeleton, but
      will not scale; `identity`'s `UserAdminController` and `platform`'s
      `BrandAdminController` already grew server-side data-table support
      (search/sort/dir/per_page) that this index should probably adopt once
      the event list needs it.
- [ ] Settle the edition/series question from `SPEC.md` — the timezone
      storage question is now settled by ADR-0027 (see SPEC.md's "Open
      questions", updated in this change).
- [ ] `src/Gdpr/` handler for organiser contact details — implemented even when
      it reports no subject data, so Compliance's fan-out is complete by
      construction.

## Done

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
      `CancelEvent`, `ArchiveEvent`, `ViewVenues`, `ManageVenues`) — not yet
      wired to policies or `permissions:sync` (see Backlog).
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

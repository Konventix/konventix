# The live-event hub: what an admitted participant sees while the event runs

Date: 2026-09-22. Status: approved design (operator decisions 2026-09-22, recorded in §12), awaiting spec review.
Release: 0.9.0, sub-project A of four (see §13). GameOps (#114, #115, #179, #303) is 0.10 or later and out of scope here.
Neighbours: Competition #296–#302 (sub-project B, adds the "Next Games" card), Agenda timetable (C), Community shoutbox and presence (D), Competition Board #117 (post-1.0).
Decisions this design rests on: ADR-0003 (module activation), ADR-0004 (events are the only coupling), ADR-0009 (typed Data, no settings blobs), ADR-0013 (capabilities), ADR-0015 (Actions), ADR-0027 (event time is wall-clock plus timezone), ADR-0033 (entrance client), D-ADM-03 (re-entry policy on the event), D-ADM-04 (an admission is valid on a day).

## 1. Goal

A guest who has been let in at the entrance — by scanning their ticket QR or by a desk operator's manual check-in — gets, on phone and desktop alike, one place that is about *this event, right now*: their ticket, the announcements, and, as the later sub-projects land, their next matches, the timetable and the shoutbox. Everyone else keeps seeing the brand site exactly as before. The operator decides per event whether that place is a page behind a topbar pill or whether the brand's home page itself turns into it while the event runs.

The motivating pain (operator, 2026-09-22): guests sign up for several competitions and fall behind on their matches, which delays everyone else. The hub is where the platform can nudge them — "your round is due, your opponent is waiting" — without forcing anything. That nudge content belongs to Competition (B); this design builds the place it appears in and the answer to the question "is this person at the event".

## 2. Facts the design rests on

Verified 2026-09-22 on `develop` (cdab1450, tag v0.8.1).

- **There is no presence.** `admission_check_ins` rows carry `direction` (`in`/`out`), `result` (`CheckInResult::Accepted|Rejected`), `scanned_at`, `event_day_id`, `checkpoint_id`; `AdmissionCheckedIn` carries all of that as ULIDs and scalars (`checkInId, admissionId, registrationId, eventId, brandId, eventDayId, checkpointId, direction, scannedAt, conditionsChecked`). Nothing derives "this user is here" from it; no module queries it. The only "out" today is a manual check-out from the desk UI.
- **Re-entry policy lives on the event** (`Konventix\Event\Enums\ReentryPolicy`: `once_only`, `once_per_day`, `unlimited`) and is mirrored into Admission's `EntranceEventProfile` (`reentry_policy`, `days` as `list<{day_id, date, sequence, label}>`), projected from `EventReentryPolicyChanged` / `EventDaysChanged`. `EntranceEventProfile::dayCovering()` compares plain `Y-m-d` strings in the event's local frame (ADR-0027). Admission's `EventSummary` read model carries the event's `timezone`. There is **no day-end time** anywhere; a day is a date.
- **An admission's holder may have no account** (`admissions.holder_user_id` nullable; claim links assign holders later). `Admission` has `holder_user_id` and `buyer_user_id`; `AdmissionCheckedIn` carries neither — the listener has to look the holder up on Admission's own model.
- **Event lifecycle**: `EventLifecycleState` `draft, announced, published, running, concluded, archived, cancelled`; events `EventStarted`, `EventConcluded`, `EventCancelled`, `EventArchived` exist.
- **Shell**: `resources/js/layouts/AppTopbarLayout.vue` + `components/AppTopbar.vue` render `page.props.nav` (`NavigationRegistry::visible(AREA_BRAND)`), `brandMenu` (operator-composed rows, Task 20) and `accountNav` (`AREA_ACCOUNT`, registered today by Registration, Admission, Commerce, Support). `HandleInertiaRequests` shares them; `nav`/`accountNav` are registry reads, `brandMenu` is a query. `resolveRegisteredComponent('<module>/<path>')` resolves a Vue component from any module without a host-side map (precedent: Support launcher, cookie dialog). `SlotRegistry` exists in Platform for typed UI slots.
- **Home**: `Route::get('/', [StaticPageController::class, 'home'])` in Platform; it already branches (admin host → console). Guests hit it constantly; it must stay cheap.
- **Per-event admin pages** live in Event (`admin/events/*`, `Dashboard.vue`); event settings are typed columns, not a blob (ADR-0009).
- **Content** entries carry a nullable `event_id`, so "announcements for this event" is a filter, not a new concept.
- **Patterns**: `Data` objects with `rules()` per endpoint, an Action per write, thin controllers, `BelongsToBrand` on every table, `registerSchedule()` with `withoutOverlapping()`, `GdprHandler` per module, `src/Metrics/<Module>Metrics.php` + `ObservabilityCoverageTest`, arch tests asserting no cross-module `use` of models.

## 3. Architecture

```
Entrance (scan / desk)                       Event module
  AdmissionCheckedIn ───┐                      EventConcluded / EventCancelled ──┐
  desk check-out ───────┤                      EventDaysChanged (day_ends_at) ───┤
                        ▼                                                        ▼
ADMISSION   ProjectEventPresence (listener + Action)   ◄──────────────────────────┘
            event_presence  (user × event: first_admitted_at, last_direction,
                             window_ends_at, hub_access)
            CloseExpiredPresenceWindows (scheduled, once_per_day events)
            emits  ParticipantAdmitted · ParticipantPresenceChanged · ParticipantHubAccessEnded
                        │
                        ▼
PLATFORM    ProjectHubAccess (listeners) → hub_access (user × brand × event, accelerator)
            ActiveEventContext (per authenticated request, one cache read)
            HubRegistry (cards + links registered by modules, gated like NavigationRegistry)
            /live  →  Hub.vue  (cards in the operator's order, links row)
            AppTopbar pill · home switch in StaticPageController::home
                        ▲
EVENT       event_hub_layouts (per event: card order/visibility, hub_replaces_home)
            admin page "Live-Hub" under the event's settings
                        ▲
MODULES     register HubCard / HubLink in their service providers:
            Admission (My Ticket) · Content (Announcements) · Resources (link: My seat)
            Support (link) · later Competition (Next Games, My Competitions) · Agenda · Community
```

Ownership follows the truth: Admission knows who was let in, so it owns presence and the access window. Platform owns the shell, so it owns the registry, the page, the pill and the home switch — as it already owns navigation. Event owns per-event settings, so it owns the layout row. No module reads another's tables; Platform's `hub_access` is a projection of Admission's events, and Event stores card *keys*, never Platform's registry objects.

## 4. Presence and the access window (Admission)

### 4.1 Table

```
admission_event_presence
  id ulid, brand_id, event_id, user_id,
  admission_id                 -- the admission that first admitted them
  first_admitted_at            -- set once
  last_check_in_at, last_direction enum(in|out)
  window_ends_at nullable      -- instant; null = open until the event concludes
  hub_access bool              -- derived, kept current so consumers never do date math
  access_ended_reason nullable enum(day_ended|concluded|cancelled|revoked)
  day_label nullable           -- the event day this scan counted against, as the pill says it
  announced_hub_access nullable bool   -- what the participant events have actually carried out
  announced_direction nullable enum(in|out)
  unique (event_id, user_id)
```

`day_label` was there from the first migration and missing from this list until the whole-branch review. The two `announced_*` columns came out of it (fix round 2): the projection runs from a queued job whose participant-event handlers are **not** queued, so one of them throwing fails the job after the row was saved — and a transition read off the saved row would let the retry decide nothing happened and swallow the event for good. The markers move only once the event has been dispatched, so a retry re-emits exactly once. Null is "nothing announced yet" and is never defaulted to a value on insert, because false would assert an announcement that was never made; `ProjectEventPresence::unsettled()` therefore treats *null* as outstanding too, or a row both of whose dispatches died would be `(hub_access false, announced null)` and matched by nothing — the participant keeping the pill after the event ended. `admission:replay-presence` re-emits from these rows, and `--seed` rebuilds them from an event's accepted check-ins for a release that lands mid-event.

### 4.2 Rules

- `AdmissionCheckedIn` with `result = accepted` and `direction = in`: resolve `holder_user_id` on the admission; **no holder → no row, no error** (a claim-link holder without an account has nothing to log in to). Upsert the row: `first_admitted_at` once, `last_check_in_at`, `last_direction = in`, `hub_access = true`, and `window_ends_at` per the event's re-entry policy:
  - `once_per_day` → the end of the day the check-in fell on (`dayCovering()`), in the event's timezone (from `EventSummary`). The day end is the new `day_ends_at` (time of day, nullable) on the day entry; default **00:00 of the following day**. Because a day may end after midnight (a LAN day ending 03:00), the default is not simply "23:59:59 of the date".
  - `once_only`, `unlimited` → `null`.
- Desk check-out (`direction = out`): `last_direction = out`, `last_check_in_at`; `hub_access` unchanged. There is no other "out" today.
- `EventConcluded`, `EventCancelled` → every row of the event: `hub_access = false`, reason `concluded`/`cancelled`.
- `AdmissionRevoked` → the row whose `admission_id` matches: `hub_access = false`, reason `revoked`. ~~A second valid admission of the same user re-admits them on the next scan.~~ **As built** the row moves over to that surviving admission instead and access never lapses — see the survivor rule in §15.
- `CloseExpiredPresenceWindows`, scheduled every minute with `withoutOverlapping()`: rows with `hub_access = true` and `window_ends_at <= now()` → `hub_access = false`, reason `day_ended`. The next day's accepted scan flips it back and computes the new window.
- **Correction (built 2026-09-22):** the listeners do *not* short-circuit on a module gate. Admission is a Core module — `AdmissionServiceProvider::$gateable = false` — so it is active for every brand and there is no `admission` feature to switch off (ADR-0003's fourth enforcement point has nothing to enforce here). What makes the feature absent for a brand that does not run a door is having nobody admitted: no accepted scan, no presence row, no `ParticipantAdmitted`, no pill and no `/live` (see §9 and §11.6).

### 4.3 Events emitted (ULIDs and scalars)

| Event | When | Payload |
|---|---|---|
| `ParticipantAdmitted` | `hub_access` goes false→true (first admission, or first scan of a new day) | `user_id, event_id, brand_id, admission_id, admitted_at, window_ends_at (nullable), day_label (nullable), event_name (nullable), event_slug (nullable)` — the last two as built, which is why Platform needs no Event listener for the pill's display data (§5.1) |
| `ParticipantPresenceChanged` | `last_direction` changed | `user_id, event_id, brand_id, direction, changed_at` |
| `ParticipantHubAccessEnded` | `hub_access` goes true→false | `user_id, event_id, brand_id, reason, ended_at` |
| `ParticipantAdmissionReplaced` | A revoked admission was replaced by a surviving one of the same holder at the same event — access never lapsed, only the admission behind it changed (added in fix round 1; see §15) | `user_id, event_id, brand_id, admission_id` (the **survivor**), `replaced_at` |

These are the contract for Platform (this design), Community's Checked-In presence (D) and Competition's "is your team here?" (B, later). Nobody may derive *access* from `ParticipantAdmissionReplaced`: access is unchanged by definition, and mirroring the id is the only reason it exists. Nobody reads `admission_event_presence` from outside Admission.

### 4.4 `day_ends_at`

A per-day setting on the event day (Event module: `event_days.day_ends_at`, built as a nullable `varchar(5)` holding `HH:MM` rather than a `time` column, so the value round-trips unchanged through the form, the `EventDaysChanged` payload and Admission's projection; UI in the existing days editor, label "Tag endet um", hint "00:00 = Mitternacht; für Nächte bis 03:00 hier 03:00"). Travels on `EventDaysChanged` as an additional key in each day entry (additive change) and is mirrored into `EntranceEventProfile.days`. Admission's `dayCovering()` is unchanged — it still decides which *date* a scan belongs to; `day_ends_at` only fixes when that day's window closes.

### 4.5 Tests

- once_per_day, Europe/Berlin, day 2 ends 03:00: a scan on day 2 at 22:00 gives `window_ends_at` = day 3 03:00 local as instant; the sweep closes it at 03:00; a scan at 09:00 on day 3 re-opens with the day-3 window.
- once_per_day, default day end: window ends 00:00 next day.
- unlimited: `window_ends_at` null; still open at 04:00 the next morning; closed by `EventConcluded`.
- check-out flips `last_direction` and emits `ParticipantPresenceChanged`, nothing else changes.
- `AdmissionRevoked` ends access with reason `revoked`.
- admission without a holder user: no row, no exception.
- rejected scan: no row.
- module off for the brand: listener returns without touching the table.
- GDPR: export lists the presence rows; deletion removes them (`GdprHandler`).

## 5. Active-event context and the hub registry (Platform)

### 5.1 `hub_access` projection and `ActiveEventContext`

Platform keeps `platform_hub_access (brand_id, user_id, event_id, admission_id, event_slug, event_name, day_label, admitted_at, window_ends_at, on_site bool, unique(event_id, user_id))`, written by `Listeners\ProjectHubAccess` on the four Admission participant events (`ParticipantAdmitted`, `ParticipantPresenceChanged`, `ParticipantAdmissionReplaced`, `ParticipantHubAccessEnded`) and on nothing else. It is a projection: dropping and replaying rebuilds it.

**Correction (built 2026-09-22):** there are no `EventDetailsUpdated`/`EventRescheduled` listeners. The event's name, slug and day label travel on `ParticipantAdmitted` itself (§4.3), so the row is complete the moment it is written and Platform keeps no projection of Event's days or details. The one Event event Platform does listen to is `EventHubLayoutChanged` (§6), which is about the operator's composition, not about a person. A name edited mid-event therefore reaches the pill on the participant's next admission, which is the right trade for a display string.

`ActiveEventContext` (request-scoped) answers `current(): ?ActiveEventData` for the authenticated user on the current brand from cache key `hub-access:{brand}:{user}` (TTL 10 min, forgotten by the same listeners). Guests: no call, no query. Several rows with access → the one with the latest `admitted_at`; `all()` feeds the switcher on the hub page. **The Inertia shared prop `activeEvent`** is this value or `null`; it is the only per-request cost the hub adds to the shell, and a test asserts a guest request performs zero hub queries.

### 5.2 `HubRegistry`

Sibling of `NavigationRegistry`, same constructor (`ModuleGate`), same visibility semantics. **As built (2026-09-22)** — the shapes below replace the sketch this section first carried:

```php
final class HubCard {
    public function __construct(
        public readonly string $module,        // gate, like NavigationItem
        public readonly string $key,           // 'admission.my-ticket'
        public readonly string $label,
        public readonly string $component,     // 'admission/hub/MyTicketCard'
        public readonly Closure $payload,      // fn(ActiveEventData, Authenticatable): Data
        public readonly ?string $permission = null,
        public readonly int $order = 100,
    ) {}
}
final class HubLink {
    module, key, label,
    ?Closure $url = null,        // fn(ActiveEventData, Authenticatable): ?string
    ?string $component = null,   // renders the link itself when a plain anchor will not do
    ?string $icon = null, ?string $permission = null, int $order = 100
}
```

A link carries a **URL closure**, not `routeName`/`routeParams`: where it points depends on the member's event (Resources' seat link names their admission), so it is resolved per request like a card's payload. A link that is not a navigation at all — Support's *Hilfe*, which opens the dialog already on the page — leaves `url` null and names a `component` instead.

**A link may withdraw itself (fix round 2, 2026-09-22).** The URL closure returns `?string`, and `null` means "not for this viewer after all": `HubPage` drops the entry rather than rendering it. Module gate and capability are decided from what the registry knows at boot, but some links depend on a fact only the member's own data carries — *Mein Sitzplatz* points at a seat claim, and a crew admission has no `registration_id` and therefore never a claim, so the link led every one of them to the picker's 404. A link rendering through a `component` has no URL to withdraw and is always shown.

The read side is `visibleCards(Authenticatable $user)` and `visibleLinks(Authenticatable $user)`, not one `visible(ActiveEventData, User)`: the registry needs the viewer, never the event, and typing the viewer as `Authenticatable` keeps Platform from importing Identity's model (ADR-0004) — the capability check goes through `Gate::forUser($user)->allows($permission)`. Both filter by module gate and permission and sort by `order`, then label. Payload and URL closures are **not** invoked here; `HubPage` invokes them only for the entries that survive the layout (§6), so a hidden card costs nothing.

### 5.3 Tests

- registry hides a card whose module is off / whose permission the viewer lacks;
- cache is forgotten on each of the four Admission participant events;
- latest admission wins with two running events; `all()` returns both;
- guest `/` and guest `/news` perform no `platform_hub_access` query.

## 6. Per-event composition (Event)

`event_hub_layouts (id, brand_id, event_id unique, hub_replaces_home bool default false, cards json)` where `cards` is `list<HubLayoutEntryData>` — `{key: string, visible: bool}` in display order (typed `Data` cast, ADR-0009); the save endpoint takes `UpdateHubLayoutData`. Admin page `admin/events/{event}/hub` ("Live-Hub") behind `Permission::UpdateEvent` (**correction:** there is no `ManageEvents` capability; Event's catalogue spells the write capability on an event `UpdateEvent`): a sortable list of every card and link Platform's registry knows (fetched through a Platform-provided `HubCatalogue` read — Event calls Platform's public service, never the reverse; Platform is the host module every module may depend on, as with navigation), on/off toggles, and the "Startseite wird zum Hub" switch with the note that it applies to signed-in admitted guests only and leaves the public home page cached.

Event emits `EventHubLayoutChanged (event_id, brand_id, cards, hub_replaces_home)` — the whole layout, never a diff. Platform's `Listeners\ProjectHubLayout` writes it into its own `platform_hub_layouts` row and forgets `hub-layout:{event}`, so the hub page and the home branch read Platform's own table and never Event's (ADR-0004); `HubLayoutResolver::for($eventId)` is the one read, cached for 10 minutes, and the empty layout is cached too. At render Platform merges: layout order first, unknown keys dropped, registry entries missing from the layout appended in registry order and visible (so a newly installed module's card appears without the operator touching anything).

Tests: reorder/hide round-trips; unknown key in a stale layout is ignored; new registry card appears at the end.

## 7. Pages, topbar, home switch

### 7.1 `/live`

Brand host, middleware `auth`, then `ensure-active-event` (Platform): no `activeEvent` → redirect `/` with flash "Kein laufendes Event, für das du eingecheckt bist." Inertia page `platform/live/Hub.vue`:

- Header: event name, day label (`day_label` as carried on `ParticipantAdmitted` — Platform keeps no projection of event days), the window hint ("Zugang bis 00:00" / "Zugang bis Eventende"), the on-site dot (`on_site`), and the event switcher when `all()` has more than one entry.
- Body: card grid, `grid-cols-1 md:grid-cols-2`, each card resolved via `resolveRegisteredComponent(card.component)` and given `card.payload` as a typed prop; a component that fails to resolve renders nothing and logs once. Then a link row.
- Cards receive their payload with the page. A card needing liveness (Next Games, shoutbox) handles it itself — Inertia partial reload of its own prop key, or Reverb on its module's channel. The hub page defines no polling of its own.

### 7.2 Topbar

`AppTopbar.vue`: when `page.props.activeEvent` is set, render a pill before the account menu — event name, on-site dot, link to `/live`; on phone it becomes the first row of the mobile menu. `nav`, `brandMenu`, `accountNav` unchanged.

### 7.3 Home switch

`StaticPageController::home`: after the admin-host branch, if the request has a user and `ActiveEventContext::current()` is set and that event's layout has `hub_replaces_home`, render the hub page (same controller path as `/live`, no redirect). Otherwise the existing home. Guests never reach the check. The logo/"Home" nav entry then points at `/home`, a new route rendering the plain brand home unconditionally, so the public page stays reachable while the hub occupies `/`.

**As built (fix round 2, 2026-09-22):** the switch travels to the frontend on `ActiveEventData` as `hubReplacesHome`, which `ActiveEventContext` resolves from `HubLayoutResolver` when it hands a row out — for admitted members only, so a guest still costs no query, and deliberately *not* baked into `hub-access:{brand}:{user}`, which has no reason to be forgotten when a layout changes. `livePillFor()` passes it through and the topbar swaps the logo's target on **that**, not on the pill: being admitted is not the same as the operator having moved the home page, and keyed off the pill alone the logo sent every admitted member to `/home` on brands that never flipped the switch. `StaticPageController::home` reads the same field instead of asking the resolver a second time.

### 7.4 Tests

- guest `/live` → login; authenticated without access → `/` with flash; with access → 200 with the cards in layout order and hidden cards absent;
- switch on: admitted user's `/` renders `platform/live/Hub`, a registered-but-not-admitted user's `/` renders the home, a guest's `/` renders the home with zero hub queries;
- `/home` renders the plain home for an admitted user with the switch on;
- topbar renders the pill with and without `on_site`, and not at all without `activeEvent`.

## 8. Cards and links at launch

| Module | Contribution | Component | Payload | Ships with |
|---|---|---|---|---|
| Admission | card **Mein Ticket**: badge QR for the active event, holder name, re-entry hint from the policy | `admission/hub/MyTicketCard` | `MyTicketCardData` (existing ticket page data, reused) | A |
| Content | card **Ankündigungen**: latest 3 published entries with `event_id` = active event, link to `/news` | `content/hub/AnnouncementsCard` | `AnnouncementsCardData` | A |
| Resources | link **Mein Sitzplatz** → the holder's seat page (`/my/admissions/{admission}/seat`), **hidden when there is no seat claim behind the admission** — the closure asks `FindSeatClaimForAdmission`, the same action the picker authorises on, and answers null for a crew badge or an unclaimed registration (§5.2) | — | route params closure | A |
| Support | link **Hilfe** → opens the support dialog (`useSupportDialog().open()`) | — | client-side action, no route | A |
| Competition | cards **Nächste Spiele**, **Meine Competitions** | `competition/hub/*` | see B's design addendum | B |
| Agenda | card **Jetzt / Gleich** | `agenda/hub/NowNextCard` | C | C |
| Community | card **Shoutbox** | `community/hub/ShoutboxCard` | D | D |

While B–D are unmerged their modules register nothing; the hub shows fewer cards. No placeholder content.

Arch tests: a card's PHP payload resolver imports only its own module; `resources/js/components/hub/*` of a module imports only its own module's generated helpers and the host's shared frontend. `tests/Feature/HubComponentBoundaryTest.php` is that test — it also pins the other half of the line, that the component *name* a module registers is under that module. Every payload is a `Data` with `#[TypeScript]`.

## 9. Capabilities, activation, GDPR, observability

- **Capabilities**: none new for participants (access is presence, not permission). Event's existing `Permission::UpdateEvent` guards the Live-Hub admin page — the same capability that edits the event itself.
- **Activation**: the presence projection is Admission's, and Admission is Core — always on, never gated (see the correction in §4.2). The shell parts are Platform's and always present but inert without `activeEvent`; the layout page is Event's. The empty state is therefore not "module off" but "nobody admitted": a brand that runs no door has no presence rows, no pill and a `/live` that redirects home.
- **GDPR**: `admission_event_presence` and `platform_hub_access` are personal data: both modules' `GdprHandler`s export and delete them; anonymisation removes the rows (a presence without a person means nothing).
- **Observability** (ADR-0059), **as built**: three counters, no gauge.
  - Admission: `admission_hub_access_total{brand,outcome}` — one increment per participant the hub opened for (`outcome=admitted`) and one per access that ended, the outcome then being `HubAccessEndReason`'s backing string (`day_ended`, `concluded`, `cancelled`, `revoked`). It replaces both metrics this section first proposed: a gauge labelled by event would have put an id in a label (`.ai/rules/observability.md`), and one counter with an outcome answers what two would have.
  - Platform: `platform_hub_views_total{brand}` (a hub render, whether at `/live` or on the home switch) and `platform_hub_card_failures_total{brand,module}` (a contribution whose closure threw and was dropped — see the implementation note on containment).
  - Panels on the platform dashboard; no alert of their own, because a dropped card already logs an error and `KonventixErrorsLogged` covers that.

## 10. Data flow, end to end

1. Desk scans a ticket → `RecordCheckIn` → `AdmissionCheckedIn(accepted, in)`.
2. Admission `ProjectEventPresence`: holder user found → row upserted, `window_ends_at` per policy → `ParticipantAdmitted`.
3. Platform `ProjectHubAccess`: `platform_hub_access` row, cache forgotten.
4. The participant's next request: `HandleInertiaRequests` reads `hub-access:{brand}:{user}` → `activeEvent` shared → pill appears.
5. `/live` (or `/` with the switch): `HubController` hands over to `Hub\HubPage`, which loads the event's layout, asks `HubRegistry::visibleCards()`/`visibleLinks()`, runs the payload and URL closures for the entries the layout keeps, renders `Hub.vue`.
6. 00:00 (or `day_ends_at`): `CloseExpiredPresenceWindows` → `hub_access = false` → `ParticipantHubAccessEnded(day_ended)` → Platform row removed, cache forgotten → pill gone, `/live` redirects. Next morning's scan restarts at step 1.
7. `EventConcluded` → every row closed → everyone loses the hub at once.

## 11. Definition of done (A)

1. Operator checks a user in at a `running` event → the user's next page load shows the pill; `/live` shows Mein Ticket and Ankündigungen cards and the Mein Sitzplatz / Hilfe links.
2. `once_per_day` event with `day_ends_at = 03:00`: at 03:01 the pill is gone and `/live` redirects; the morning scan brings it back.
3. `unlimited` event: still there the next morning; gone after `EventConcluded`.
4. Operator turns on "Startseite wird zum Hub" → the admitted user's `/` is the hub, a guest's `/` is unchanged and performs no hub query, `/home` shows the plain home.
5. Operator hides the Ankündigungen card and moves Mein Ticket last → `/live` reflects it without a deploy.
6. ~~Admission off for the brand~~ — Admission is Core and cannot be switched off (§4.2). The equivalent state is a brand that admits nobody: no presence rows, no pill, no errors, `/live` redirects home, and a guest's `/` performs no hub query.
7. `permissions:sync`, pint, phpstan, and the arch tests (no cross-module model imports, card resolvers module-local) are green.

## 12. Decisions recorded (operator, 2026-09-22)

| # | Decision |
|---|---|
| D-HUB-01 | Hub access = admitted once during the running event, on phone and desktop. `once_per_day` events close the window at the day's end (`day_ends_at`, default 00:00); other events stay open until the event concludes. |
| D-HUB-02 | "On site" (last check-in direction) is shown as a badge and emitted as `ParticipantPresenceChanged`; it never gates the hub. The desk's manual check-out is the only "out". |
| D-HUB-03 | Presence feeds a later Community presence model (Offline/Away/Online/Checked-In, plus active-in-room across many rooms: shoutbox, per match, per competition). Community consumes the events; it does not read Admission's table. |
| D-HUB-04 | The hub is a page (`/live`) plus a topbar pill; per event the operator may additionally make the home page the hub for admitted signed-in users, knowing the guest home stays the cheap cached page. |
| D-HUB-05 | Launch cards: Mein Ticket, Ankündigungen; links: Mein Sitzplatz (a link, not a card), Hilfe. Competition/Agenda/Community cards arrive with their sub-projects. Operator reorders/hides per event, module defaults otherwise. |
| D-HUB-06 | Ownership: Admission owns presence and the window; Platform owns registry, page, pill and home switch; Event owns the per-event layout row. (Approach 1 over a dedicated `hub` module or Event-owned hub.) |
| D-HUB-07 | "Next Games" is Competition's feature (my upcoming matches, nudging, no enforcement) and works with hand-run servers; GameOps only enriches it with connect details in 0.10+. |

## 13. Where this sits in 0.9.0

| Sub-project | Content | Depends on |
|---|---|---|
| **A — this design** | presence, window, registry, `/live`, pill, home switch, Live-Hub admin page, Mein Ticket + Ankündigungen cards, two links | — |
| B — Competition | #296–#302 plus Next Games / Meine Competitions cards and the Steam-linked-account signup rule on `Discipline` (`requires_linked_provider`) | A's registry and `ParticipantAdmitted` |
| C — Agenda | event timetable, Jetzt/Gleich card | A |
| D — Community | shoutbox room per event, presence model (D-HUB-03), Shoutbox card | A's presence events |

A ships first and alone is testable at a real event stage (§11).

## 14. Out of scope

GameOps (0.10+), Competition Board #117 (post-1.0), push notifications for nudges (Messaging's web-push driver, D-MSG-02, slice 13), friends/"who is here" (Community, later), any hub content for people who are registered but not yet admitted (the existing `/my/*` pages serve them).

## 15. Implementation notes (2026-09-22, sub-project A shipped)

Four decisions the design did not anticipate, recorded here because they are
the ones a reader of the code will otherwise wonder about. The corrections to
what this document originally claimed are marked inline in §§4.2, 4.4, 5.1,
5.2, 6 and 9.

- **One module's failure costs its own card, never the page** (D-HUB-05
  review). Platform cannot vet a foreign module's payload and by ADR-0003 must
  not care what it reads, so `Hub\HubPage` runs every payload and URL closure
  inside its own `try`: an entry whose closure throws is dropped, logged once
  with its module and key, counted on
  `platform_hub_card_failures_total{brand,module}`, and the rest of the page
  renders. The case that forced it: a brand whose entrance signing key has been
  retired makes `admission.my-ticket` throw, and without containment the
  event-day page would 500 for everyone on that brand — including the *Hilfe*
  link that is the participant's only way to say so. A dropped card is a gap,
  not a blank placeholder.
- **Every presence listener is queued and `afterCommit`.** The door must never
  wait on the hub or fail over it (ADR-0033):
  `ProjectPresenceOnAdmissionCheckedIn`, `EndHubAccessOnEventClosed` and
  `EndHubAccessOnAdmissionRevoked` all implement `ShouldQueue` with
  `$afterCommit = true`. A synchronous projection that hit the presence table's
  unique key — two lanes scanning the same holder within a second — would roll
  an *accepted* scan back; `afterCommit` additionally drops the job with a
  rolled-back scan, so a presence row can never outlive the check-in that
  caused it, and the revocation listener's survivor lookup (below) sees the
  revoked state committed rather than a half-finished batch.
- **A revoked admission ends access only if it was the last one.** §4.2 said
  "`AdmissionRevoked` → the row whose `admission_id` matches loses access". As
  built, `ProjectEventPresence::endForAdmission()` first looks for another
  admission of the same holder at the same event that is not revoked; if one
  exists the presence row is moved over to it and access continues, with no
  `ParticipantHubAccessEnded` emitted. Only a holder with nothing left loses
  the hub. Without this, cancelling one of a group booking's two tickets would
  have thrown the holder out of an event they are standing in. The re-point
  emits **`ParticipantAdmissionReplaced`** instead, and Platform's projection
  keeps its mirror of `admission_id` in step with it: that id is what
  Resources' *Mein Sitzplatz* link is built from, so a silent re-point would
  have left the participant with a hub link pointing at the admission that had
  just been revoked (found in review, fixed in fix round 1).
- **The request memo remembers whose answer it holds.**
  `Hub\ActiveEventContext` is a scoped binding that memoises its answer for the
  request, and the memo is keyed by brand-and-user. A container that outlives
  one viewer — the test runner reusing scoped instances between requests, or
  any code switching the authenticated user mid-request — re-resolves instead
  of handing the previous viewer's events to the next one.

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
  unique (event_id, user_id)
```

### 4.2 Rules

- `AdmissionCheckedIn` with `result = accepted` and `direction = in`: resolve `holder_user_id` on the admission; **no holder → no row, no error** (a claim-link holder without an account has nothing to log in to). Upsert the row: `first_admitted_at` once, `last_check_in_at`, `last_direction = in`, `hub_access = true`, and `window_ends_at` per the event's re-entry policy:
  - `once_per_day` → the end of the day the check-in fell on (`dayCovering()`), in the event's timezone (from `EventSummary`). The day end is the new `day_ends_at` (time of day, nullable) on the day entry; default **00:00 of the following day**. Because a day may end after midnight (a LAN day ending 03:00), the default is not simply "23:59:59 of the date".
  - `once_only`, `unlimited` → `null`.
- Desk check-out (`direction = out`): `last_direction = out`, `last_check_in_at`; `hub_access` unchanged. There is no other "out" today.
- `EventConcluded`, `EventCancelled` → every row of the event: `hub_access = false`, reason `concluded`/`cancelled`.
- `AdmissionRevoked` → the row whose `admission_id` matches: `hub_access = false`, reason `revoked`. A second valid admission of the same user re-admits them on the next scan.
- `CloseExpiredPresenceWindows`, scheduled every minute with `withoutOverlapping()`: rows with `hub_access = true` and `window_ends_at <= now()` → `hub_access = false`, reason `day_ended`. The next day's accepted scan flips it back and computes the new window.
- Listeners short-circuit when the `admission` module is off for the brand (ADR-0003); the whole feature is then absent, not broken.

### 4.3 Events emitted (ULIDs and scalars)

| Event | When | Payload |
|---|---|---|
| `ParticipantAdmitted` | `hub_access` goes false→true (first admission, or first scan of a new day) | `user_id, event_id, brand_id, admission_id, admitted_at, window_ends_at (nullable), day_label (nullable)` |
| `ParticipantPresenceChanged` | `last_direction` changed | `user_id, event_id, brand_id, direction, changed_at` |
| `ParticipantHubAccessEnded` | `hub_access` goes true→false | `user_id, event_id, brand_id, reason, ended_at` |

These are the contract for Platform (this design), Community's Checked-In presence (D) and Competition's "is your team here?" (B, later). Nobody reads `admission_event_presence` from outside Admission.

### 4.4 `day_ends_at`

A per-day setting on the event day (Event module: `event_days.day_ends_at time nullable`; UI in the existing days editor, label "Tag endet um", hint "00:00 = Mitternacht; für Nächte bis 03:00 hier 03:00"). Travels on `EventDaysChanged` as an additional key in each day entry (additive change) and is mirrored into `EntranceEventProfile.days`. Admission's `dayCovering()` is unchanged — it still decides which *date* a scan belongs to; `day_ends_at` only fixes when that day's window closes.

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

Platform keeps `platform_hub_access (brand_id, user_id, event_id, event_slug, event_name, window_ends_at, day_label, on_site bool, unique(user_id, event_id))`, written by listeners on the three Admission events plus `EventDetailsUpdated`/`EventRescheduled` for the slug/name. It is a projection: dropping and replaying rebuilds it.

`ActiveEventContext` (request-scoped) answers `current(): ?ActiveEventData` for the authenticated user on the current brand from cache key `hub-access:{brand}:{user}` (TTL 10 min, forgotten by the same listeners). Guests: no call, no query. Several rows with access → the one with the latest `admitted_at`; `all()` feeds the switcher on the hub page. **The Inertia shared prop `activeEvent`** is this value or `null`; it is the only per-request cost the hub adds to the shell, and a test asserts a guest request performs zero hub queries.

### 5.2 `HubRegistry`

Sibling of `NavigationRegistry`, same constructor (`ModuleGate`), same `visible()` semantics:

```php
final class HubCard {
    public function __construct(
        public string $module,        // gate, like NavigationItem
        public string $key,           // 'admission.my-ticket'
        public string $label,
        public string $component,     // 'admission/hub/MyTicketCard'
        public Closure $payload,      // fn(ActiveEventData, User): Data
        public ?string $permission = null,
        public int $order = 100,
    ) {}
}
final class HubLink { module, key, label, string $routeName, array $routeParams (closure allowed), ?string $icon, ?string $permission, int $order }
```

`visible(ActiveEventData $event, User $user): Collection` filters by module gate and `$user->can($permission)`; payload closures are **not** invoked here. `HubController` invokes them only for cards that survive the layout (§6), so a hidden card costs nothing.

### 5.3 Tests

- registry hides a card whose module is off / whose permission the viewer lacks;
- cache is forgotten on each of the three Admission events;
- latest admission wins with two running events; `all()` returns both;
- guest `/` and guest `/news` perform no `platform_hub_access` query.

## 6. Per-event composition (Event)

`event_hub_layouts (id, brand_id, event_id unique, hub_replaces_home bool default false, cards json → HubLayoutData)` where `HubLayoutData` is `list<{key: string, visible: bool}>` in display order (typed `Data` cast, ADR-0009). Admin page `admin/events/{event}/hub` ("Live-Hub") behind Event's `ManageEvents`: a sortable list of every card and link Platform's registry knows (fetched through a Platform-provided `HubCatalogue` read — Event calls Platform's public service, never the reverse; Platform is the host module every module may depend on, as with navigation), on/off toggles, and the "Startseite wird zum Hub" switch with the note that it applies to signed-in admitted guests only and leaves the public home page cached.

Event emits `EventHubLayoutChanged (event_id, brand_id)`; Platform forgets `hub-layout:{event}`. At render Platform merges: layout order first, unknown keys dropped, registry entries missing from the layout appended in registry order and visible (so a newly installed module's card appears without the operator touching anything).

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
| Resources | link **Mein Sitzplatz** → the holder's seat page (`/my/admissions/{admission}/seat`) | — | route params closure | A |
| Support | link **Hilfe** → opens the support dialog (`useSupportDialog().open()`) | — | client-side action, no route | A |
| Competition | cards **Nächste Spiele**, **Meine Competitions** | `competition/hub/*` | see B's design addendum | B |
| Agenda | card **Jetzt / Gleich** | `agenda/hub/NowNextCard` | C | C |
| Community | card **Shoutbox** | `community/hub/ShoutboxCard` | D | D |

While B–D are unmerged their modules register nothing; the hub shows fewer cards. No placeholder content.

Arch tests: a card's PHP payload resolver imports only its own module; `resources/js/components/hub/*` of a module imports only its own module's types and shared `@/components/ui`. Every payload is a `Data` with `#[TypeScript]`.

## 9. Capabilities, activation, GDPR, observability

- **Capabilities**: none new for participants (access is presence, not permission). Event's existing `ManageEvents` guards the Live-Hub admin page.
- **Activation**: the presence projection is Admission's and follows the `admission` gate; the shell parts are Platform's and always present but inert without `activeEvent`; the layout page is Event's. With Admission off for a brand nothing is ever admitted and the hub never appears — correct empty state.
- **GDPR**: `admission_event_presence` and `platform_hub_access` are personal data: both modules' `GdprHandler`s export and delete them; anonymisation removes the rows (a presence without a person means nothing).
- **Observability** (ADR-0059): Admission gauge `admission_participants_present{brand,event}` (rows with `last_direction = in` and `hub_access`), counter `admission_hub_access_ended_total{brand,reason}`; Platform counter `platform_hub_views_total{brand}`. Panels on the event dashboard row; no alert.

## 10. Data flow, end to end

1. Desk scans a ticket → `RecordCheckIn` → `AdmissionCheckedIn(accepted, in)`.
2. Admission `ProjectEventPresence`: holder user found → row upserted, `window_ends_at` per policy → `ParticipantAdmitted`.
3. Platform `ProjectHubAccess`: `platform_hub_access` row, cache forgotten.
4. The participant's next request: `HandleInertiaRequests` reads `hub-access:{brand}:{user}` → `activeEvent` shared → pill appears.
5. `/live` (or `/` with the switch): `HubController` loads the event's layout, asks `HubRegistry::visible()`, runs payload closures for the visible cards, renders `Hub.vue`.
6. 00:00 (or `day_ends_at`): `CloseExpiredPresenceWindows` → `hub_access = false` → `ParticipantHubAccessEnded(day_ended)` → Platform row removed, cache forgotten → pill gone, `/live` redirects. Next morning's scan restarts at step 1.
7. `EventConcluded` → every row closed → everyone loses the hub at once.

## 11. Definition of done (A)

1. Operator checks a user in at a `running` event → the user's next page load shows the pill; `/live` shows Mein Ticket and Ankündigungen cards and the Mein Sitzplatz / Hilfe links.
2. `once_per_day` event with `day_ends_at = 03:00`: at 03:01 the pill is gone and `/live` redirects; the morning scan brings it back.
3. `unlimited` event: still there the next morning; gone after `EventConcluded`.
4. Operator turns on "Startseite wird zum Hub" → the admitted user's `/` is the hub, a guest's `/` is unchanged and performs no hub query, `/home` shows the plain home.
5. Operator hides the Ankündigungen card and moves Mein Ticket last → `/live` reflects it without a deploy.
6. Admission off for the brand → no presence rows, no pill, no errors, all listeners short-circuit.
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

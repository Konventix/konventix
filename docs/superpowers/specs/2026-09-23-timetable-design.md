# The timetable: what is on, when, and where

Date: 2026-09-23. Status: approved design (operator decisions 2026-09-22/23, recorded in §11).
Release: 0.9.0, sub-project **C** of four. Issue: #106 (Timetables aus Eventula und LanCore).
Neighbours: sub-project A — the live-event hub (`2026-09-22-event-hub-design.md`, shipped) whose card registry this fills; B — Competition #296–#302, whose slice 1 gates this design's second half; D — Community shoutbox. Out of scope and unchanged: the Competition Board #117 (a planning tool for the desk, post-1.0), which is not this.
Decisions this design rests on: ADR-0003 (module activation), ADR-0004 (events are the only coupling), ADR-0009 (typed Data), ADR-0013 (capabilities), ADR-0014 (ULIDs, no cross-module FKs), ADR-0027 (event time is wall-clock plus timezone), ADR-0029 (operator console).

## 1. Goal

An organizer plans the event's programme — talks, workshops, the opening, the band, the tournaments worth announcing — and publishes it. A participant, on a phone in a hall or at a desk beforehand, sees what is running now, what starts next, and what is on at 14:00. The programme is public: it needs no account and no ticket.

This is #106's "Programmplanung der Veranstaltung", and it is deliberately the *published* view. The Competition Board (#117) plans tournaments with duration estimates for the desk; the timetable announces to everyone what will happen. They touch the same hours and are different things.

## 2. Facts the design rests on

Verified 2026-09-23 on `develop` (7bdd0987).

- **`app-modules/agenda/` is a `make:module` scaffold**: `AgendaServiceProvider` with `moduleName() = 'agenda'`, every `src/*` a `.gitkeep`, `routes/web/agenda.php` empty. `SPEC.md` is written but carries five open questions and a "post-release module, do not start" banner; §11 answers the questions and this design supersedes the banner.
- **Competition has no start time.** `Competition::$fillable` is `discipline_id, event_id, name, slug, format, signup_opens_at, signup_closes_at, published_at` — there is no `starts_at`, no `CompetitionStatus` enum, and the module emits only `EntrantRegistered`, `MatchScheduled`, `MatchFinished`, `MatchResultCorrected`. The start time and the lifecycle events arrive with **#296** (D-CMP-15). Agenda may not query Competition's tables (ADR-0004), so the competition half of this design waits on that slice.
- **There is no room entity in the platform, and Resources' `Resource` is a seat.** Its docblock: "One bookable thing. In unit mode that is literally one seat" — a 40-seat room is 40 resources. Places are created in bulk by `SavePlan`'s chunked upsert; `PublishPlan`/`UnpublishPlan` bulk-update `is_published` across a whole plan. There is no per-room lifecycle to listen to, and a per-resource event would fire once per seat. Event's `Venue` is a building with an address (`name`, `slug`, `address_line1`, `postal_code`, `city`, `country_code`), not a hall inside one; `zone_id`/`group_id` on a resource are plan-internal groupings with no names of their own. **Agenda therefore owns its own locations** (§4.3).
- **Event days exist**: `event_days` with `date`, `sequence` (1-based), `label`, and since sub-project A `day_ends_at` (`HH:MM`, nullable). `EventDaysChanged` carries the complete current set. `Event` holds `starts_at`/`ends_at` as wall-clock plus `timezone`, read through `startsAtInstant()`/`endsAtInstant()` (ADR-0027).
- **The hub's card registry is live** (sub-project A): a module registers a `HubCard(module, key, label, component, Closure $payload, ?permission, order)` or a `HubLink` from `registerHub()` on its service provider; `HubPage` resolves the payload only for cards the operator's layout keeps, and contains a throwing card rather than failing the page. Components live at `app-modules/<module>/resources/js/components/hub/<Name>.vue` and are resolved by the name `<module>/hub/<Name>`. `tests/Feature/HubComponentBoundaryTest.php` enforces that such a component imports no other module's code.
- **Public event pages**: `/events/{event:slug}` (`EventLandingController::show`) and `/events/{event:slug}/calendar.ics` exist in Event's `routes/web/`.
- **Patterns**: `Data` objects with `rules()` per endpoint (ADR-0009), an Action per write (ADR-0015), thin controllers, `BelongsToBrand` on every table, `src/Metrics/<Module>Metrics.php` + `ObservabilityCoverageTest`, `GdprHandler` per module where personal data exists, explicit listener registration (discovery is off), participant-facing copy in German and console copy in English.

## 3. Architecture

```
EVENT           EventDaysChanged ─────────────┐   (day set for the switcher)
                                              ▼
                                          AGENDA
                agenda_locations ─ the module's own places: name + optional
                                   venue_id (Event, by ULID, no FK)
                agenda_tracks    ─ the grid's columns, ordered, per event
                agenda_items     ─ title, description, starts_at?/ends_at?,
                                   track, location (own row + free note),
                                   state: draft | published | cancelled
                agenda_competition_entries ─ which competitions appear (half 2)
                                               │
                emits AgendaItemPublished / Rescheduled / Cancelled
                                               │
                ┌──────────────────────────────┴───────────────────────────┐
                ▼                          ▼                               ▼
        /events/{slug}/programm     admin/events/{event}/agenda      hub card
        (public, no auth)           (ManageAgenda)                   "Jetzt / Gleich"
                                                                     via A's HubRegistry
COMPETITION     CompetitionPublished / Rescheduled / Cancelled ──► agenda_competitions
(half 2, #296)  (Competition owns starts_at; Agenda never writes it)   (read model)
```

Ownership follows the truth: Competition knows when a tournament starts, Event knows the days — each publishes, Agenda projects. Agenda owns the *programme* — which things are announced, in what order, under what heading — and, because the platform has no room concept, the places they happen in. It reads no other module's tables.

## 4. The model

### 4.1 `agenda_tracks`

```
id ulid, brand_id, event_id (plain ulid — Event is another module, no FK)
name, position smallint
unique (event_id, name)
```

A track is a column of the grid — "Bühne", "Turniere", "Workshops". An entity rather than a free-text field because a grid needs a stable, ordered column set. Deleting a track leaves its items untracked rather than deleting them.

### 4.2 `agenda_items`

```
id ulid, brand_id, event_id (plain ulid), track_id (nullable, FK within module)
title, description nullable
starts_at timestamptz nullable, ends_at timestamptz nullable
location_id ulid nullable            -- agenda_locations, FK within module
location_note string nullable        -- free text, for a place that is not worth a row
speaker string nullable              -- plain value; external speakers need no account
state enum(draft|published|cancelled), cancelled_reason nullable
timestamps
index (event_id, starts_at)
```

**At least one of `starts_at`/`ends_at` is required** — enforced in the Action and by a check constraint. Three shapes are legal and each renders differently (§5):

| Shape | Means | Renders |
|---|---|---|
| start + end | a block with a duration | spans its rows |
| start only | "begins at 14:00", open end | one row, then stops |
| end only | "until 08:00", open start | one row at that time |

The open-ended shape is what a competition needs until the duration estimator exists, and what "Einlass ab 12:00" needs for ever.

**Location** is `location_id` — a row of Agenda's own `agenda_locations` (§4.3) — with `location_note` for the one-off that is not worth a row ("Parkplatz", "vor der Halle"). Both nullable: an item may name no place at all. Renaming a location renames it everywhere it is used, which is the whole reason it is a row rather than a string.

### 4.3 `agenda_locations` — Agenda's own places

```
id ulid, brand_id, event_id (plain ulid), name, venue_id ulid nullable,
position smallint, timestamps
unique (event_id, name)
```

"Halle 3", "Bühne", "Workshopraum", "Foyer" — maintained by the organizer in the programme editor beside the tracks. `venue_id` optionally names Event's venue the place sits in, by ULID and without a foreign key (ADR-0014); it is display context, nothing depends on it.

Agenda owns this because **the platform has no room concept to borrow** (§2): Resources models bookable seats, and Event's `Venue` is a building. Owning it costs one small table and gives the programme what a reference is for — pick from a list, rename once, no typos — without inventing a cross-module contract for an entity that does not exist. If a room ever becomes a first-class thing elsewhere, `agenda_locations` is the seam that adopts it: one nullable column, no change to items.

Deleting a location leaves its items with `location_id = null`; the editor asks first and offers to move them.

### 4.5 `agenda_competition_entries` and `agenda_competitions` *(half two — after #296)*

```
agenda_competition_entries: id, brand_id, event_id, competition_id (ulid), track_id nullable, position
agenda_competitions (read model): competition_id (unique), brand_id, event_id, name, slug,
                                  starts_at nullable, state, timestamps
```

**The programme editor selects** which of the event's competitions appear — the choice belongs to the person composing the published programme, not to whoever runs the tournament. The read model is projected from Competition's lifecycle events (`CompetitionPublished`, `CompetitionRescheduled`, `CompetitionCancelled` — names as #296 settles them); **Competition owns `starts_at` and Agenda never writes it**. A selected competition renders as an open-ended block at its start time. When the duration estimator (#117) can answer an end, the block gains one; nothing here changes.

## 5. Rendering

**Rows are the distinct times that actually occur** on the day — every `starts_at` and `ends_at` of every visible item, sorted — not fixed 30-minute slots. A 14:15 item needs no special case and there are no empty rows at 03:00.

**Columns are the event's tracks**, in `position` order. An item spans **half-open** `[start, end)` — from its start row up to, but not including, the row at its end (`rowspan` in a plain HTML table; the browser does the layout, with no absolute positioning and no overlap maths). Half-open because a row is a time *boundary*, not a slot: with Eröffnung 14:00–15:00 followed by Bandcheck 15:00–16:00 in one track, an inclusive span would make both claim the 15:00 row and break the table. Two items in one track at one time sit side by side in the same cell.

**Untracked items** render as a full-width band above the grid ("Ohne Track"), which is also where an all-day item belongs.

**Multi-day**: one grid per event day, with a day switcher built from `event_days` (`label` or "Tag N"); the day a participant arrives on is preselected when the event is running.

**Phone**: the columns collapse to one chronological list, the track becomes a label on each entry. Same data, same page, a CSS breakpoint.

**Cancelled items stay visible**, struck through, with the reason if there is one — a participant who memorised 15:00 Lötkurs needs to learn it is off, not to find it missing.

## 6. Surfaces

| Surface | Route | Who | What |
|---|---|---|---|
| Public programme | `/events/{event:slug}/programm` | everyone, no auth | the grid, the day switcher, a track filter; published and cancelled items |
| Programme editor | `admin/events/{event}/agenda` | `ManageAgenda` | items CRUD with the three time shapes, track management (add/rename/reorder/remove), location management (add/rename/reorder/remove) and the picker over them, publish/cancel, and — half two — the competition selection |
| Hub card | via A's `HubRegistry`, key `agenda.now-next` | admitted participants | **"Jetzt / Gleich"**: what is running now and what starts next, link to the full programme |

The public page is genuinely public: a programme is an announcement, and gating it behind an account would defeat its purpose. Reading needs no capability; only editing does.

## 7. Public contract (events out)

| Event | Emitted when | Payload |
|---|---|---|
| `AgendaItemPublished` | a draft item becomes visible | `agenda_item_id, event_id, brand_id, title, starts_at (nullable), ends_at (nullable)` |
| `AgendaItemRescheduled` | a published item's times change | `agenda_item_id, event_id, brand_id, starts_at (nullable), ends_at (nullable)` |
| `AgendaItemCancelled` | a published item is cancelled | `agenda_item_id, event_id, brand_id, reason (nullable)` |

Nothing consumes these in 0.9.0. They exist so Messaging can notify a participant that "Lötkurs 15:00 fällt aus" without Agenda ever learning what a notification is, and so D's presence work has something to react to later.

## 8. Capabilities, activation, GDPR, observability

- **Capability**: one — `ManageAgenda` (`agenda.manage-agenda`), covering items, tracks and the competition selection. Reading is public. Added to `permissions:sync` (ADR-0013); granted by Agenda's own default role `agenda.programme-editor` ("Programme Editor"). *Amended 2026-09-22, during Task 5:* this line originally said "granted to the Event Admin default role", which cannot be built — `DefaultRole::of()` refuses a role that grants another module's capability (ADR-0013), and Event ships no default role at all. Platform's `platform.brand-admin` is `everything` and already holds it; the role above is the grant for somebody who is not the brand admin.
- **Activation**: Pennant feature `agenda`, gated at all four enforcement points (ADR-0003). With it off: no public programme route, no admin page, no hub card, and the Competition listeners short-circuit. Nothing else degrades — no module depends on Agenda. *Amended 2026-09-23, during #331 Task 3:* the Competition listeners are **not** short-circuited. They run for every brand, like `ProjectEventSummary`, because a brand that switches Agenda on after its tournaments were published must find them selectable instead of waiting for someone to run `competition:announce-competitions`. The cost is a row per announced competition for brands without Agenda: a name, a slug and a start the competition's own public page already shows. Nothing acts on those rows while the module is off, and the route, admin page and hub card gates are unchanged.
- **GDPR**: none. A programme holds no personal data — `speaker` is a plain value an organizer typed, published deliberately on a public page, and is not a user reference. If speaker accounts ever arrive, they arrive with a handler.
- **Observability** (ADR-0059): counter `agenda_items_published_total{brand}`; a panel on the platform dashboard. No alert — an unpublished programme is a planning state, not an incident.

## 9. Testing

- Three time shapes round-trip through the Action and render correctly (span, single row, single row).
- An item with neither start nor end is refused.
- Row derivation: the distinct times of a day, sorted, with a 14:15 item and an item that ends after the last start.
- A renamed location renames everywhere it is used; a deleted location leaves its items placeless rather than deleting them; an item with only a `location_note` renders that note.
- Cancelled item is visible and struck; draft item is absent from the public page and present in the editor.
- Multi-day: the switcher lists the event's days and each renders only its own items.
- Hub card: "Jetzt / Gleich" picks the running item and the next one; empty state when the programme is empty; absent when `agenda` is off.
- Feature-off test and a module-boundary test for the hub component (A's arch test covers the last one automatically once the component exists).

## 10. Definition of done

1. An organizer creates tracks, adds items in all three time shapes, picks a location from the programme's own list, publishes, and the public page shows the grid on a phone and on a desktop.
2. Renaming a location in the editor changes every item that uses it.
3. Cancelling an item leaves it visible and struck.
4. A multi-day event switches days; the running day is preselected.
5. An admitted participant's hub shows "Jetzt / Gleich" and reaches the programme from it.
6. With `agenda` off for the brand: no routes, no card, no listeners, no errors.
7. *(half two, after #296)* The editor selects two of the event's competitions; they appear at their Competition-owned start times as open-ended blocks, and rescheduling in Competition moves them.

## 11. Decisions recorded (operator, 2026-09-22/23)

| # | Decision |
|---|---|
| D-AGD-01 | C ships in **two halves**: organizer-entered items now; the competition half after B slice 1 (#296) provides `starts_at` and the lifecycle events. C is not blocked on B. |
| D-AGD-02 | **The programme selects the competitions**, not the other way round — Agenda holds the selection; Competition does not know it is being displayed. |
| D-AGD-03 | **Competition owns the start time** and it is fixed; the end is computed later (#117's estimator). A competition renders as an open-ended block until then. |
| D-AGD-04 | An agenda item carries **start and/or end** — at least one, not necessarily both. |
| D-AGD-05 | **Slot grid**: time rows × track columns, an item spanning its rows; a plain table, no absolute positioning. Phone collapses to one chronological list. Keep the UX simple. |
| D-AGD-06 | **Track is an entity** per event (name + position); rows are the distinct times that occur, not fixed slots. *(controller's call)* |
| D-AGD-07 | **Agenda owns its locations** (`agenda_locations`: name + optional `venue_id`), maintained in the programme editor. Revised 2026-09-23 after finding that Resources' `Resource` is a seat, not a room — the original plan (three new Resources lifecycle events feeding a read model) would have fired once per seat and pointed the programme at seat `A-14`. No cross-module contract; no Resources change. |
| D-AGD-08 | **Per-item publication state only** (draft / published / cancelled); no programme-level switch — the event's own lifecycle already gates public pages. Cancelled stays visible and struck. |
| D-AGD-09 | Out of scope: personal agendas and bookmarks (Community, later), item capacity or sign-up (Registration when it matters), speaker accounts, and mirroring individual matches (whole competitions only). |

## 12. What this answers from `SPEC.md`'s open questions

- *Personal agenda here or Community?* — **Community, later** (D-AGD-09).
- *Mirrored matches: projection or reference at render?* — **neither; whole competitions only**, as a selection plus a read model projected from Competition's events (D-AGD-02, D-AGD-03).
- *How does an item name its location without Resources?* — the question dissolves: **Agenda owns `agenda_locations`** and never involved Resources (D-AGD-07).
- *Multi-day and timezones?* — one grid per `event_day`, times wall-clock in the event's zone (ADR-0027), §5.
- *Do external speakers need accounts?* — **no**, a plain value (D-AGD-09).

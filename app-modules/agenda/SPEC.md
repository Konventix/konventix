# Agenda — Specification

> Stable document. Describes what this module *is*. Task tracking lives in
> `TODO.md`.

Built in 0.9.0 as sub-project **C** of that release (#106), to the design in
`docs/superpowers/specs/2026-09-23-timetable-design.md`, whose §11 records the
operator decisions **D-AGD-01 … D-AGD-09** this document points at. The
module was built in two halves: the organizer-entered programme in 0.9.0, and
the competition selection in 0.9.1 (#331), once Competition had a
`starts_at` and lifecycle events (#296, D-AGD-01).

## Purpose

Agenda owns the published programme of an event: what happens, when, and
where. It absorbs LanCore's Program domain and generalizes it away from
LAN-specific items — a talk, a workshop, a showmatch, an opening ceremony and
a social slot are all the same kind of thing here. For participants it answers
"what is on at 14:00"; for organizers it is the one place a schedule is
planned and published.

The programme is **public**: no account, no ticket. Gating an announcement
behind a login would defeat the point of announcing it. Only editing needs a
capability.

## Owns

- **Agenda item** (`agenda_items`) — a scheduled programme entry belonging to
  one event: title, description, speaker (a plain value), a track, a place,
  and a state of `draft` / `published` / `cancelled` with an optional
  cancellation reason. It carries a **start and/or an end — at least one, not
  necessarily both** (D-AGD-04): "Einlass ab 12:00" and "bis 08:00" are real
  programme entries, and an open-ended block is also what a competition needs
  until a duration estimator exists (D-AGD-03).
- **Track** (`agenda_tracks`) — the grid's columns: a named, ordered grouping
  per event (stage, room track, theme). An entity rather than a string, so it
  can be renamed and reordered (D-AGD-06).
- **Location** (`agenda_locations`) — the module's **own** places: "Halle 3",
  "Bühne", "Foyer", maintained in the programme editor beside the tracks, with
  an optional `venue_id` naming Event's venue they sit in (ULID, no FK). An
  item may instead carry a one-off `location_note`, or no place at all.
  Agenda owns this because **the platform has no room entity to borrow**
  (D-AGD-07): Resources' `Resource` is a *seat* — a 40-seat room is 40
  resources, created in bulk, with no per-room lifecycle to listen to — and
  Event's `Venue` is a building with a postal address, not a hall inside one.
  Pointing the programme at either would have named a place "A-14". One small
  table buys what a reference is for: pick from a list, rename once, no typos.
  If a room ever becomes a first-class entity elsewhere, this table is the
  seam that adopts it — one nullable column, no change to items.
- **Publication state per item** (D-AGD-08), and the participant-facing views
  of the programme. There is no programme-level publish switch: the event's
  own lifecycle already decides whether *any* public page exists.
- **The competition selection** (`agenda_competition_entries`, half two,
  D-AGD-01) — which of the event's publicly announced competitions appear in
  the programme, under which track and in what order. **The programme selects
  the competitions** (D-AGD-02): the editor picks them, and Competition does
  not know it is being displayed. An entry outlives an unpublish, so that a
  republished tournament comes back where the organizer put it.
- **The competition read model** (`agenda_competitions`) — name, slug, start
  and whether it is cancelled, for every announced competition, projected from
  Competition's contract events alone (ADR-0004; no FK to Competition).
  Competition **owns the start time**, and Agenda never writes it (D-AGD-03).
  A selected competition is an **open-ended block** in the grid, the phone
  list and "Jetzt / Gleich", marked "Turnier", struck through when cancelled,
  and linked to its tournament page only while Competition is on for the
  brand. It gains an end when #117's duration estimator exists.

## Does not own

- **The event, its days and its venue** — Event. Agenda holds a read model
  (`agenda_event_summaries`) of the five facts the programme needs — slug,
  name, timezone, day set, lifecycle state — projected from Event's contract
  events. It reads none of Event's tables (ADR-0004).
- **Seats and anything bookable** — Resources. Nothing crosses between the
  two modules; see `agenda_locations` above.
- **Match scheduling, results and a competition's start time** — Competition.
  Whole competitions may appear in the programme (half two); individual
  matches never do (D-AGD-09).
- **Crew shift plans** — Staffing. A shift is not an agenda item, even when it
  covers the same hour.
- **Announcements and articles about the programme** — Content.
- **Reminder and change notifications** — Messaging. That is what the events
  below exist for.
- **Who is allowed to attend** — Admission. Agenda publishes a schedule; it
  gates access to nothing.
- **Personal agendas and bookmarks** — Community, later (D-AGD-09).

## Public contract (events)

Events are the only way other modules may react to this one (ADR-0004).
Payloads carry ULIDs and scalars — never Eloquent models — and the instants
are ISO-8601 strings, either of which may be null because an item may be open
at one end (D-AGD-04).

| Event | Emitted when | Payload |
|---|---|---|
| `AgendaItemPublished` | a draft item becomes publicly visible | `agendaItemId`, `eventId`, `brandId`, `title`, `startsAt?`, `endsAt?` |
| `AgendaItemRescheduled` | a **published** item's times change | `agendaItemId`, `eventId`, `brandId`, `startsAt?`, `endsAt?` |
| `AgendaItemCancelled` | a **published** item is called off | `agendaItemId`, `eventId`, `brandId`, `reason?` |

All three are announcements to people, which is why all three are about items
that were already public: moving or cancelling a draft tells nobody, and
publishing twice announces once. Nothing consumes them in 0.9.0 — they exist
so Messaging can one day say "Lötkurs 15:00 fällt aus" without Agenda ever
learning what a notification is.

## Consumes

Listeners are idempotent and replayable (ADR-0004).

| Event | Owning module | Why |
|---|---|---|
| `EventCreated`, `EventDetailsUpdated`, `EventRescheduled`, `EventDaysChanged` | Event | slug, name, timezone and the day set the switcher is built from |
| `EventAnnounced`, `EventPublished`, `EventUnpublished`, `EventStarted`, `EventConcluded`, `EventCancelled`, `EventArchived` | Event | the lifecycle state that decides whether the public programme page resolves at all |
| `CompetitionPublished`, `CompetitionRescheduled`, `CompetitionCancelled`, `CompetitionUnpublished`, `CompetitionAnnounced` | Competition | `agenda_competitions`: a published competition's name, slug and start; a moved start; a cancellation (the block stays, struck); an unpublish (the row goes, the selection stays); and the backfill's restatement of all of it. **Ungated, like `ProjectEventSummary`**, for the same reason |

`Listeners\ProjectEventSummary` and `Listeners\ProjectProgrammeCompetition` are
deliberately **not** short-circuited on the Pennant gate, unlike a listener
that *does* something: a brand that switches Agenda on halfway through
planning must find its events already projected, or every programme editor
would 404 on them, and must find its already-published tournaments
selectable rather than wait for someone to run
`competition:announce-competitions`. What that costs is a row per announced
competition for a brand without Agenda, holding what the competition's own
public page already shows (design §8, amended 2026-09-23).

**Not propagated:** renaming or re-slugging an announced competition, or
moving it to another event. No contract event carries those, so the block
keeps the old name, or a dead link, until `competition:announce-competitions`
runs again (#332).

## Capabilities

Declared in `src/Enums/Permission.php`; roles that grant them are per-brand
data, not code (ADR-0013).

- **`agenda.manage-agenda`** (`Permission::ManageAgenda`) — compose the
  programme: items, tracks, places, publishing, cancelling, and the
  competition selection. One case is the whole list, because that is one
  job done by one person; splitting it would produce grants nobody would hand
  out apart.

Reading a published programme needs **no capability at all**.

The default role `agenda.programme-editor` ("Programme Editor", ADR-0045)
carries it, materialized per brand by `permissions:sync` and on activation.
Platform's `platform.brand-admin` is `everything` and therefore holds it too.

## Activation

Pennant feature **`agenda`**; the module is gateable. Registration happens at
boot regardless, and the gate applies at all four enforcement points
(ADR-0003): routes, navigation, UI slots and listeners.

With the feature off for a brand: the public programme route and the console
editor both 404, the hub card `agenda.now-next` is still *registered* but
filtered out at render, and no other module notices — nothing depends on
Agenda.

The deliberate exceptions are the two projections above,
`agenda_event_summaries` and `agenda_competitions`, which keep running so
that switching the module on later finds the brand's events and announced
tournaments already there. The organizer's selection is written only by the
editor, so a brand that has never had Agenda on has none.

## Surfaces

| Surface | Route | Who |
|---|---|---|
| Public programme | `/events/{slug}/programm` | everyone, no auth — grid per event day, day switcher, phone list |
| Programme editor | `admin/events/{event}/agenda` | `ManageAgenda`, on the operator console (ADR-0029) |
| Hub card "Jetzt / Gleich" | Platform's `HubRegistry`, key `agenda.now-next` | admitted participants |

The public page is cache-free and brand-scoped; a cancelled item stays on it,
struck through with its reason, because a participant who memorised 15:00
needs to learn it is off, not to find it missing. A cancelled competition
block stays the same way. The editor's competition selection is its own
form and its own endpoint, `PUT admin/events/{event}/agenda/competitions`.

## GDPR

**None.** A programme holds no personal data: `speaker` is a plain value an
organizer typed and deliberately published on a public page, not a user
reference. There is no `src/Gdpr/` handler, and that is the answer rather than
an omission. If speaker accounts ever arrive (D-AGD-09), they arrive with a
handler.

## Observability

ADR-0059. One counter, `agenda_items_published_total{brand}`
(`src/Metrics/AgendaMetrics.php`, recorded by
`Listeners\Metrics\RecordAgendaMetrics`), on the Platform dashboard as
"Programme items published". **No alert**: an empty or unmoving programme is a
brand still planning, not an incident. `docs/operations.md` § *Programme* has
the runbook, including the `event:replay-details --with-state` step a brand
needs when it activates Agenda on events that predate the module.

The competition half adds **no metric, on purpose**. It keeps no operational
state of its own: `agenda_competitions` is a projection whose freshness is
the backfill's, exactly like `agenda_event_summaries`, which has none either,
and a selection is an editor's save like any other. What can go wrong with it
is a missing or stale row, which `competition:announce-competitions`
repairs and `docs/operations.md` § *Tournaments in the programme*
describes.

## Answered questions

The five open questions this document used to carry, answered by design §12:

- *Does a personal agenda (bookmarked items) belong here or to Community?* —
  **Community, later.** Out of scope for 0.9.0 (D-AGD-09).
- *How closely should a mirrored Competition match track the original?* — the
  question dissolves: **individual matches are never mirrored**. Whole
  competitions are selected into the programme and projected into a read
  model from Competition's own events, which keep owning the start time
  (D-AGD-02, D-AGD-03, D-AGD-09).
- *Does an agenda item need its own capacity/registration concept?* — **no.**
  That is Registration's job on the day it matters (D-AGD-09).
- *Multi-day and timezone handling?* — one grid per `event_day`, with a day
  switcher built from Event's projected day set; every time on the page is
  wall-clock in the **event's** timezone, never the reader's (ADR-0027,
  design §5).
- *Do external speakers ever need accounts?* — **no**, a plain value on the
  item, for ever unless somebody asks otherwise (D-AGD-09).

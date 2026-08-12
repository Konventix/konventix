# Agenda — Specification

> Stable document. Describes what this module *is*. Task tracking lives in
> `TODO.md`.

> **Post-release module.** Agenda is Phase 3 in `docs/ROADMAP.md` — deliberately
> after release scope. Do not start building it earlier. This document records
> intent at the level the surrounding architecture already fixes; everything
> below that is marked proposed or listed as an open question, and is expected
> to change when the module is actually designed.

## Purpose

Agenda owns the published programme of an event: what happens, when, and where.
It absorbs LanCore's Program domain and generalizes it away from LAN-specific
items — a talk, a workshop, a showmatch, an opening ceremony, and a social slot
are all the same kind of thing here. For participants it answers "what is on at
14:00"; for organizers it is the one place a schedule is planned and published.

## Owns

- **Agenda item** — a scheduled programme entry belonging to one event: title,
  description, start and end time, status (draft / published / cancelled).
- **Track** — the grouping a participant navigates by (stage, room track, theme).
- **Attribution** — who presents or hosts an item. For platform users this is a
  read model keyed by Identity's user ULID; external speakers are plain values
  held here.
- **Publication state** of the programme as a whole, and the participant-facing
  views of it.

## Does not own

- **The event and its venue** — Event. Agenda items reference an event by ULID.
- **Rooms, stages, and anything bookable with a time dimension** — Resources.
  An agenda item points at a resource by ULID; when Resources is inactive for a
  brand it carries a plain location label instead.
- **Match scheduling and results** — Competition (`CompetitionSchedule` stays
  there). If a match appears in the programme it is a mirror; Competition's
  times remain authoritative.
- **Crew shift plans** — Staffing. A shift is not an agenda item, even when it
  covers the same hour.
- **Announcements and articles about the programme** — Content.
- **Reminder and change notifications** — Messaging.
- **Who is allowed to attend** — Admission. Agenda publishes a schedule; it does
  not gate access to anything.

## Public contract (events)

Events are the only way other modules may react to this one (ADR-0004).
Payloads carry ULIDs and scalars — never Eloquent models.

All rows below are **proposed**; nothing consumes them yet.

| Event | Emitted when | Payload |
|---|---|---|
| `AgendaItemPublished` (proposed) | A draft item becomes publicly visible | `agenda_item_id`, `event_id`, `starts_at`, `ends_at` |
| `AgendaItemRescheduled` (proposed) | A published item's time changes | `agenda_item_id`, `event_id`, `starts_at`, `ends_at` |
| `AgendaItemCancelled` (proposed) | A published item is cancelled | `agenda_item_id`, `event_id` |

## Consumes

Events from other modules that this module listens to, and the read models it
maintains from them.

All rows below are **proposed**. Listeners must be idempotent and replayable
(ADR-0004) and must short-circuit when the feature is off for the brand
(ADR-0003).

| Event | Owning module | Why |
|---|---|---|
| Match scheduling events (proposed) | Competition | Mirror matches into the programme without importing Competition models |
| Speaker/user profile events (proposed) | Identity | Keep the attribution read model current |

## Capabilities

Declared in `src/Enums/Permission.php`. Roles that grant them are per-brand
data, not code (ADR-0013).

Not yet defined. The expected shape is a small catalog around managing and
publishing programme items; reading a published programme is public and needs
no capability. The final list is decided when the module is built.

## Activation

Pennant feature: **`agenda`**. Registration happens at boot regardless; the
feature gates routes, navigation, UI slots, and listeners (ADR-0003).

With the feature off, a brand has no programme: no agenda routes or navigation,
no schedule on event pages, no mirrored matches. Nothing else degrades — no
other module depends on Agenda.

## Open questions

- Does a participant's personal agenda (bookmarked items) belong here or to
  Community? It is cross-cutting and undecided.
- How closely should a mirrored Competition match track the original — one-way
  projection, or a reference resolved at render time?
- Does an agenda item need its own capacity/registration concept, or is that
  always Registration's job when it matters?
- Multi-day and timezone handling for events that span venues.
- Whether external speakers ever need accounts, or stay plain values forever.

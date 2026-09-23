# Agenda — TODO

> Living document. Update it in the same PR that changes the code: check off
> what you completed, add follow-ups you discovered. Do not generate status
> dashboards, percentages, or summary tables here.

The module was built in 0.9.0 (sub-project **C**, #106) to
`docs/superpowers/specs/2026-09-23-timetable-design.md`. It ships in two
halves; the first is done and the second is below.

## Next

- [ ] **The competition half** (D-AGD-01, design §4.5): `agenda_competition_entries`
      — the programme editor's selection of which of the event's competitions
      appear — and `agenda_competitions`, the read model projected from
      Competition's `CompetitionPublished` / `CompetitionRescheduled` /
      `CompetitionCancelled`. **Blocked on #296**, which gives Competition a
      `starts_at` and those lifecycle events; Agenda may not query
      Competition's tables (ADR-0004), so there is nothing to build until
      then. Competition owns the start time and Agenda never writes it
      (D-AGD-03); a selected competition renders as an open-ended block until
      #117's duration estimator can answer an end (D-AGD-02).
      #296 has landed, so this is no longer blocked on it. **One
      precondition remains, on Competition's side:** `UnpublishCompetition`
      emits no event, so a competition unpublished after it was selected
      would stay in the public programme. Competition must add
      `CompetitionUnpublished(competitionId, eventId, brandId,
      unpublishedAt)` and this projection must remove its row on it, in the
      same change that builds this half (competition design §14,
      "Unpublishing a competition tells nobody").

## Backlog

Deferred by D-AGD-09 unless noted — each is somebody else's module, and none
is waiting on anything here:

- [ ] **Personal agendas / bookmarked items** — Community, when it exists. It
      is a per-participant list over Agenda's ids, not a second programme.
- [ ] **Capacity or sign-up per item** — Registration, on the day an item
      needs a seat limit. Agenda announces; it does not admit.
- [ ] **Speaker accounts** — today `speaker` is a plain value on the item and
      there is no GDPR handler because there is no personal data. Making a
      speaker a user reference means a `src/Gdpr/` exporter arrives with it.
- [ ] **Linking a location to a Resources zone** — `agenda_locations.venue_id`
      already names Event's venue; a zone is the finer seam, and it stays
      closed until Resources has something room-shaped to point at (D-AGD-07).

## Done

- [x] Write `SPEC.md` — purpose, ownership boundary, event contract.
- [x] **Task 1 — the model.** `agenda_tracks`, `agenda_locations`,
      `agenda_items` with the three time shapes and the check constraint that
      refuses an item with neither end; `Track`, `Location`, `AgendaItem`,
      `ItemState`; factories.
- [x] **Task 2 — the write path.** `AgendaItemData`, `TrackData`,
      `LocationData`; `SaveAgendaItem`, `SaveTracks`, `SaveLocations`,
      `PublishAgendaItem`, `CancelAgendaItem`; the three contract events; the
      `ManageAgenda` capability.
- [x] **Task 3 — the grid.** `ProgrammeBuilder` (rows are the distinct times
      that occur, half-open spans, untracked band, per-day split in the
      event's timezone) and `NowNext`.
- [x] **Task 4 — the three surfaces.** Public `/events/{slug}/programm`, the
      editor at `admin/events/{event}/agenda`, the hub card
      `agenda.now-next`, and the `agenda_event_summaries` projection they all
      read the event from.
- [x] **Task 5 — capabilities, activation, observability, docs.** The
      `agenda.programme-editor` default role; the
      `agenda_items_published_total` counter and its Platform-dashboard
      panel; `docs/operations.md` § *Programme*; this file and `SPEC.md`.
- [x] **The activation backfill.** `event:replay-details --with-state`
      (Event's command) re-emits each event's current lifecycle event, so a
      brand activating Agenda on events that predate the module gets a public
      programme instead of a 404. Every listener on Event's seven lifecycle
      events was checked for idempotency first; the list is in
      `docs/operations.md`, and a new listener on one of them owes the same
      check. The same run now re-emits `EventDaysChanged` too, unflagged
      (whole-branch review, I1): without it `agenda_event_summaries.days`
      stayed `NULL` and a backfilled event's programme rendered **empty**
      with a 200 however many items were published — the harder half of the
      gap to notice. Its two listeners were audited the same way, and
      Admission's `entrance_event_profiles.days` is filled by the same run.

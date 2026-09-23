<?php

declare(strict_types=1);

namespace Konventix\Agenda\Http\Controllers\Admin;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Agenda\Actions\CancelAgendaItem;
use Konventix\Agenda\Actions\PublishAgendaItem;
use Konventix\Agenda\Actions\SaveAgendaItem;
use Konventix\Agenda\Actions\SaveCompetitionSelection;
use Konventix\Agenda\Actions\SaveLocations;
use Konventix\Agenda\Actions\SaveTracks;
use Konventix\Agenda\Data\AgendaItemData;
use Konventix\Agenda\Data\CompetitionEntryData;
use Konventix\Agenda\Data\CompetitionSelectionRowData;
use Konventix\Agenda\Data\LocationData;
use Konventix\Agenda\Data\TrackData;
use Konventix\Agenda\Enums\Permission;
use Konventix\Agenda\Models\AgendaItem;
use Konventix\Agenda\Models\EventSummary;
use Konventix\Agenda\Models\Location;
use Konventix\Agenda\Models\Track;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Modules\ModuleGate;
use Konventix\Platform\Operators\OperatorContext;

/**
 * Operator console: the programme editor (design §6, ADR-0029).
 *
 * Every method opens with `Gate::authorize(ManageAgenda)` — one capability
 * covers items, tracks and places, because composing a programme is one
 * job (design §8). Reading the published programme needs none;
 * `ProgrammeController` is the surface that proves it.
 *
 * Two things the admin host forces on every query here. `BrandScope`
 * hardens to zero rows on it (ADR-0029), so every read escapes it
 * explicitly and filters the brand by hand — the brand being the one on
 * the event's own summary row, never ambient context. And the module gate
 * cannot be route middleware, because this host resolves no brand to gate
 * on: `ModuleGate::isActiveForBrand()` is the explicit-brand form written
 * for exactly this, and a brand that has Agenda switched off finds no
 * editor (ADR-0003).
 *
 * The event is a **string id, not a bound model** — Agenda may not import
 * Event's Eloquent model (ADR-0004), so the id is resolved against this
 * module's own read model, kept current by `Listeners\ProjectEventSummary`.
 *
 * Writes are one Action each (ADR-0015). Tracks and places are saved as
 * whole sets by the page's single form; items are written one at a time,
 * because publishing and calling one off are separate acts with separate
 * announcements behind them (design §7).
 */
final class AgendaAdminController
{
    public function edit(string $event): Response
    {
        Gate::authorize(Permission::ManageAgenda->value);

        $summary = $this->summary($event);

        return Inertia::render('admin/agenda/Edit', [
            'event' => [
                'id' => $summary->event_id,
                'name' => $summary->name,
                'slug' => $summary->slug,
                'timezone' => $summary->eventTimezone(),
            ],
            'tracks' => array_map(
                static fn (Track $track): array => TrackData::fromModel($track)->toArray(),
                $this->tracks($summary)->all(),
            ),
            'locations' => array_map(
                static fn (Location $location): array => LocationData::fromModel($location)->toArray(),
                $this->locations($summary)->all(),
            ),
            // The raw rows, not the grid: the editor edits items, and
            // `ProgrammeBuilder`'s output is a rendering of published ones.
            // `state` and `cancelledReason` ride along because the two
            // buttons per row need them and `AgendaItemData` deliberately
            // carries neither (see its docblock).
            'items' => $this->items($summary)->map(static fn (AgendaItem $item): array => [
                ...AgendaItemData::fromModel($item)->toArray(),
                'state' => $item->state->value,
                'cancelledReason' => $item->cancelled_reason,
            ])->all(),
            'days' => $summary->programmeDays(),
            // The event's competitions and the organizer's pick of them
            // (design §4.5, D-AGD-02), withdrawn selections included — see
            // `CompetitionSelectionRowData`.
            'competitions' => array_map(
                static fn (CompetitionSelectionRowData $row): array => $row->toArray(),
                CompetitionSelectionRowData::ofEvent($summary->event_id, $summary->brand_id),
            ),
        ]);
    }

    /**
     * The grid's columns and the event's places, both as complete sets:
     * `SaveTracks`/`SaveLocations` take the whole desired state and derive
     * the creates, renames and deletions from it.
     */
    public function update(Request $request, string $event, SaveTracks $saveTracks, SaveLocations $saveLocations): RedirectResponse
    {
        Gate::authorize(Permission::ManageAgenda->value);

        $summary = $this->summary($event);

        // The per-row rules come from the Data classes themselves (ADR-0009)
        // rather than being restated here; prefixing them is what keeps the
        // error keys indexed, so the editor can show the failure on the row
        // it belongs to.
        $rules = [
            'tracks' => ['present', 'array'],
            'locations' => ['present', 'array'],
        ];

        foreach (TrackData::rules() as $field => $rule) {
            $rules["tracks.*.{$field}"] = $rule;
        }

        foreach (LocationData::rules() as $field => $rule) {
            $rules["locations.*.{$field}"] = $rule;
        }

        $validated = $request->validate($rules);

        $saveTracks->handle($summary->event_id, $summary->brand_id, array_map(
            static fn (array $track): TrackData => TrackData::from($track),
            array_values((array) ($validated['tracks'] ?? [])),
        ));

        $saveLocations->handle($summary->event_id, $summary->brand_id, array_map(
            static fn (array $location): LocationData => LocationData::from($location),
            array_values((array) ($validated['locations'] ?? [])),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Programme saved.')]);

        return to_route('admin.events.agenda.edit', $summary->event_id);
    }

    /**
     * Which of the event's competitions the programme shows, under which
     * track and in what order (design §4.5, D-AGD-02) — a whole set, like
     * tracks and places, but its own form and endpoint: the selection is
     * the half of the editor that reads Competition's announcements, and
     * a refused competition must not cost the operator a track rename.
     *
     * The rules are `CompetitionEntryData`'s, lifted onto `competitions.*.`
     * exactly as `update()` lifts `TrackData`'s. Whether the ids belong to
     * *this* event is the Action's check, keyed the same way.
     */
    public function updateCompetitions(Request $request, string $event, SaveCompetitionSelection $saveCompetitionSelection): RedirectResponse
    {
        Gate::authorize(Permission::ManageAgenda->value);

        $summary = $this->summary($event);

        $rules = [
            'competitions' => ['present', 'array'],
        ];

        foreach (CompetitionEntryData::rules() as $field => $rule) {
            $rules["competitions.*.{$field}"] = $rule;
        }

        $validated = $request->validate($rules);

        $saveCompetitionSelection->handle($summary->event_id, $summary->brand_id, array_map(
            static fn (array $entry): CompetitionEntryData => CompetitionEntryData::from($entry),
            array_values((array) ($validated['competitions'] ?? [])),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Competition selection saved.')]);

        return to_route('admin.events.agenda.edit', $summary->event_id);
    }

    public function storeItem(Request $request, string $event, SaveAgendaItem $saveAgendaItem): RedirectResponse
    {
        Gate::authorize(Permission::ManageAgenda->value);

        $summary = $this->summary($event);

        $saveAgendaItem->handle(
            $summary->event_id,
            $summary->brand_id,
            AgendaItemData::validateAndCreate($request->all()),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Item added.')]);

        return to_route('admin.events.agenda.edit', $summary->event_id);
    }

    public function updateItem(Request $request, string $event, string $item, SaveAgendaItem $saveAgendaItem): RedirectResponse
    {
        Gate::authorize(Permission::ManageAgenda->value);

        $summary = $this->summary($event);

        $saveAgendaItem->handle(
            $summary->event_id,
            $summary->brand_id,
            AgendaItemData::validateAndCreate($request->all()),
            $this->item($summary, $item),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Item saved.')]);

        return to_route('admin.events.agenda.edit', $summary->event_id);
    }

    /**
     * Publishing is its own endpoint rather than a `state` field on the
     * item form, because it is the moment `AgendaItemPublished` is
     * announced — and a form field that could set it would announce
     * nothing (see `AgendaItemData`).
     */
    public function publishItem(string $event, string $item, PublishAgendaItem $publishAgendaItem): RedirectResponse
    {
        Gate::authorize(Permission::ManageAgenda->value);

        $summary = $this->summary($event);

        $publishAgendaItem->handle($this->item($summary, $item));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Item published.')]);

        return to_route('admin.events.agenda.edit', $summary->event_id);
    }

    /**
     * D-AGD-08: calling an item off keeps it on the public page, struck
     * through and carrying the reason — which is why the reason is a field
     * here and not an afterthought.
     */
    public function cancelItem(Request $request, string $event, string $item, CancelAgendaItem $cancelAgendaItem): RedirectResponse
    {
        Gate::authorize(Permission::ManageAgenda->value);

        $summary = $this->summary($event);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $reason = $validated['reason'] ?? null;

        $cancelAgendaItem->handle($this->item($summary, $item), is_string($reason) ? $reason : null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Item cancelled.')]);

        return to_route('admin.events.agenda.edit', $summary->event_id);
    }

    /**
     * Deleting is for the item that should never have been there. An item
     * somebody has already seen is *cancelled*, not deleted — the editor
     * says so, and D-AGD-08 is why.
     */
    public function destroyItem(string $event, string $item): RedirectResponse
    {
        Gate::authorize(Permission::ManageAgenda->value);

        $summary = $this->summary($event);

        $this->item($summary, $item)->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Item removed.')]);

        return to_route('admin.events.agenda.edit', $summary->event_id);
    }

    /**
     * The event as this module knows it, or a 404 — for an event nobody
     * projected, for one on a brand of another operator, and for one whose
     * brand has Agenda switched off.
     *
     * The summary is found by event id across the whole installation, so
     * the operator check is what keeps this editor to the admin host's own
     * brands: the capability was checked against that operator's team, and
     * without it a programme editor of operator Y who knew an event id of
     * operator X could rewrite X's programme. Platform's own consoles scope
     * the same way (`BrandMenuAdminController`).
     */
    private function summary(string $event): EventSummary
    {
        /** @var EventSummary $summary */
        $summary = EventSummary::withoutBrandScope()->where('event_id', $event)->firstOrFail();

        $operator = app(OperatorContext::class)->current() ?? abort(404);

        abort_unless(
            Brand::query()->whereKey($summary->brand_id)->where('operator_id', $operator->getKey())->exists(),
            404,
        );

        abort_unless(
            app(ModuleGate::class)->isActiveForBrand('agenda', $summary->brand_id),
            404,
        );

        return $summary;
    }

    /**
     * @return Collection<int, Track>
     */
    private function tracks(EventSummary $summary): Collection
    {
        return Track::withoutBrandScope()
            ->where('brand_id', $summary->brand_id)
            ->where('event_id', $summary->event_id)
            ->orderBy('position')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Location>
     */
    private function locations(EventSummary $summary): Collection
    {
        return Location::withoutBrandScope()
            ->where('brand_id', $summary->brand_id)
            ->where('event_id', $summary->event_id)
            ->orderBy('position')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, AgendaItem>
     */
    private function items(EventSummary $summary): Collection
    {
        return AgendaItem::withoutBrandScope()
            ->where('brand_id', $summary->brand_id)
            ->where('event_id', $summary->event_id)
            ->orderByRaw('coalesce(starts_at, ends_at) asc nulls last')
            ->orderBy('id')
            ->get();
    }

    /**
     * Scoped to this event *and* this brand: an id from another event is
     * not this editor's to publish, cancel or delete.
     */
    private function item(EventSummary $summary, string $item): AgendaItem
    {
        /** @var AgendaItem */
        return AgendaItem::withoutBrandScope()
            ->where('brand_id', $summary->brand_id)
            ->where('event_id', $summary->event_id)
            ->findOrFail($item);
    }
}

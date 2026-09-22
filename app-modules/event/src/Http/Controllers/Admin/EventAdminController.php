<?php

declare(strict_types=1);

namespace Konventix\Event\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Event\Actions\CreateEvent;
use Konventix\Event\Actions\SyncEventDays;
use Konventix\Event\Actions\UpdateEventDetails;
use Konventix\Event\Data\EventData;
use Konventix\Event\Enums\Permission;
use Konventix\Event\Models\Event;
use Konventix\Event\Models\EventDay;
use Konventix\Event\Models\Venue;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Models\Scopes\BrandScope;
use Konventix\Platform\Modules\ModuleGate;
use Konventix\Platform\Operators\ConsoleSelection;
use Konventix\Platform\Slots\SlotRegistry;

/**
 * Operator console: event management (ADR-0029, ADR-0003 "slots are an
 * activation gate point").
 *
 * Events are brand-owned (ADR-0010); the admin host hardens `BrandScope` to
 * nothing (ADR-0029), so every query here uses the explicit
 * `withoutBrandScope()` escape hatch deliberately — including relation
 * loads, which re-apply the scope on the related model (.ai/rules).
 *
 * The dashboard composes Event's own header with contributions from other
 * modules through Platform's `SlotRegistry`; this controller never queries
 * Registration's or Admission's models.
 */
class EventAdminController
{
    use HasDataTableQuery;

    public function __construct(
        private readonly SlotRegistry $slots,
        private readonly ModuleGate $modules,
    ) {}

    public function index(Request $request, ConsoleSelection $selection): Response
    {
        Gate::authorize(Permission::ViewEvents->value);

        $perPage = $this->resolvePerPage($request);
        $sort = $this->resolveSort($request, allowedSorts: ['name', 'slug', 'starts_at', 'state'], default: 'starts_at');

        if (! $request->filled('sort')) {
            $sort['direction'] = 'desc';
        }

        // Only the brand half of the console selection applies here: an
        // events list is what the event selector points into, so filtering it
        // by the selected event would leave one row and no way back.
        $brand = $this->resolveSelectionFilter($request, 'brand', $selection->brandId());

        $events = Event::withoutBrandScope()
            ->when($brand !== null, fn ($query) => $query->forBrand($brand))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->trim().'%';

                $query->where(function ($inner) use ($term): void {
                    $inner->where('name', 'ilike', $term)->orWhere('slug', 'ilike', $term);
                });
            })
            ->orderBy($sort['column'], $sort['direction'])
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('admin/events/Index', [
            'events' => $events->through(fn (Event $event): array => [
                'id' => $event->getKey(),
                'name' => $event->name,
                'slug' => $event->slug,
                'state' => $event->state->value,
                'startsAt' => $event->starts_at->format('Y-m-d H:i'),
                'timezone' => $event->timezone,
            ]),
            'brands' => $this->brandOptions(),
            'filters' => $this->currentFilters($request, $sort, $perPage, ['brand' => $brand]),
        ]);
    }

    public function store(Request $request, CreateEvent $createEvent): RedirectResponse
    {
        Gate::authorize(Permission::CreateEvent->value);

        $brandId = $request->validate(['brand_id' => ['required', 'ulid', 'exists:brands,id']])['brand_id'];
        $data = EventData::validateAndCreate($request->all());

        $event = $createEvent->handle($brandId, $data, $this->validatedDays($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Event created.')]);

        return to_route('admin.events.edit', $event->getKey());
    }

    public function show(string $event): Response
    {
        Gate::authorize(Permission::ViewEvents->value);

        $model = $this->find($event);

        return Inertia::render('admin/events/Dashboard', [
            'event' => [
                'id' => $model->getKey(),
                'name' => $model->name,
                'state' => $model->state->value,
                // ADR-0027: always go through the timezone-aware accessors.
                'startsAt' => $model->startsAtInstant()->format('Y-m-d H:i'),
                'endsAt' => $model->endsAtInstant()->format('Y-m-d H:i'),
                'timezone' => $model->timezone,
            ],
            'transitions' => $this->legalTransitions($model),
            // Enforcement point 3 (ADR-0003) for a link *out* of this page
            // rather than a card rendered inside it, so `SlotRegistry`
            // cannot carry it: that registry gates on the *ambient* brand,
            // and the operator admin host resolves none (ADR-0029), so
            // every gateable module's contribution would be hidden here
            // whatever the brand had switched on. `isActiveForBrand()` is
            // the explicit-brand form written for exactly this — the same
            // call `AgendaAdminController` makes to decide whether the
            // editor exists at all, asked here so the button does not
            // offer a 404. `community` answers the same question for the
            // shoutbox desk (`ModerationController`). Event names the module, never its code
            // (ADR-0004); `ContentController` asks Platform the same way.
            'modules' => [
                'agenda' => $this->modules->isActiveForBrand('agenda', (string) $model->brand_id),
                'community' => $this->modules->isActiveForBrand('community', (string) $model->brand_id),
            ],
            'slots' => $this->slots->for('event.admin.dashboard')
                ->map(fn (array $entry): array => [
                    'module' => $entry['module'],
                    'component' => $entry['component'],
                    'props' => $entry['props'],
                ])
                ->all(),
        ]);
    }

    public function edit(string $event, MediaLibrary $library): Response
    {
        Gate::authorize(Permission::UpdateEvent->value);

        $model = $this->find($event);

        return Inertia::render('admin/events/Edit', [
            'event' => [
                'id' => $model->getKey(),
                'state' => $model->state->value,
                'brandId' => $model->brand_id,
                ...EventData::fromModel($model)->toArray(),
            ],
            // The banner picker's candidates (#292), through Platform's own
            // doorway rather than a MediaItem query of this module's.
            'media' => $library->pickableImages($model->brand_id),
            // Relation loads re-apply BrandScope on the related model, which
            // hardens to zero rows on the admin host (.ai/rules) — escape it
            // per relation or the page renders empty day/venue lists.
            'days' => $model->days()->withoutGlobalScope(BrandScope::class)->get()->map(fn (EventDay $day): array => [
                'date' => $day->date->toDateString(),
                'label' => $day->label,
                'day_ends_at' => $day->day_ends_at,
            ])->all(),
            'venues' => $model->venues()->withoutGlobalScope(BrandScope::class)->get()->map(fn (Venue $venue): array => [
                'id' => $venue->getKey(),
                'name' => $venue->name,
                'city' => $venue->city,
                'isPrimary' => (bool) $venue->pivot?->getAttribute('is_primary'),
            ])->all(),
            'availableVenues' => Venue::withoutBrandScope()
                ->where('brand_id', $model->brand_id)
                ->orderBy('name')
                ->get(['id', 'name', 'city'])
                ->map(fn (Venue $venue): array => [
                    'id' => $venue->getKey(),
                    'name' => $venue->name,
                    'city' => $venue->city,
                ])->all(),
        ]);
    }

    public function update(Request $request, string $event, UpdateEventDetails $updateEventDetails, SyncEventDays $syncEventDays): RedirectResponse
    {
        Gate::authorize(Permission::UpdateEvent->value);

        $model = $this->find($event);

        $updateEventDetails->handle($model, EventData::validateAndCreate($request->all()));
        $syncEventDays->handle($model, $this->validatedDays($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Event updated.')]);

        return to_route('admin.events.edit', $model->getKey());
    }

    private function find(string $event): Event
    {
        /** @var Event */
        return Event::withoutBrandScope()->findOrFail($event);
    }

    /**
     * `day_ends_at` is `HH:MM` on a 24-hour clock, exactly what
     * `<input type="time">` submits (D-HUB-01); anything else is refused
     * here rather than stored and misread by the hub's clock.
     *
     * @return list<array{date: string, label: string|null, day_ends_at: string|null}>
     */
    private function validatedDays(Request $request): array
    {
        $validated = $request->validate([
            'days' => ['array'],
            'days.*.date' => ['required', 'date_format:Y-m-d'],
            'days.*.label' => ['nullable', 'string', 'max:255'],
            'days.*.day_ends_at' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ]);

        return array_values(array_map(
            fn (array $day): array => [
                'date' => $day['date'],
                'label' => $day['label'] ?? null,
                'day_ends_at' => $day['day_ends_at'] ?? null,
            ],
            $validated['days'] ?? [],
        ));
    }

    /**
     * The lifecycle transitions legal from the event's current state — the
     * Dashboard renders exactly these as buttons, so the UI cannot offer an
     * illegal transition the Actions would refuse anyway.
     *
     * @return list<string>
     */
    private function legalTransitions(Event $event): array
    {
        return match ($event->state->value) {
            'draft' => ['announce', 'publish', 'cancel'],
            'announced' => ['publish', 'cancel'],
            'published' => ['start', 'unpublish', 'reschedule', 'cancel'],
            'running' => ['conclude', 'cancel'],
            'concluded' => ['archive'],
            default => [],
        };
    }
}

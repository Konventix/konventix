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
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Scopes\BrandScope;
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

    public function __construct(private readonly SlotRegistry $slots) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::ViewEvents->value);

        $perPage = $this->resolvePerPage($request);
        $sort = $this->resolveSort($request, allowedSorts: ['name', 'slug', 'starts_at', 'state'], default: 'starts_at');

        if (! $request->filled('sort')) {
            $sort['direction'] = 'desc';
        }

        $events = Event::withoutBrandScope()
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
            'filters' => $this->currentFilters($request, $sort, $perPage),
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
            'slots' => $this->slots->for('event.admin.dashboard')
                ->map(fn (array $entry): array => [
                    'module' => $entry['module'],
                    'component' => $entry['component'],
                    'props' => $entry['props'],
                ])
                ->all(),
        ]);
    }

    public function edit(string $event): Response
    {
        Gate::authorize(Permission::UpdateEvent->value);

        $model = $this->find($event);

        return Inertia::render('admin/events/Edit', [
            'event' => [
                'id' => $model->getKey(),
                'state' => $model->state->value,
                ...EventData::fromModel($model)->toArray(),
            ],
            // Relation loads re-apply BrandScope on the related model, which
            // hardens to zero rows on the admin host (.ai/rules) — escape it
            // per relation or the page renders empty day/venue lists.
            'days' => $model->days()->withoutGlobalScope(BrandScope::class)->get()->map(fn (EventDay $day): array => [
                'date' => $day->date->toDateString(),
                'label' => $day->label,
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
     * @return list<array{date: string, label: string|null}>
     */
    private function validatedDays(Request $request): array
    {
        $validated = $request->validate([
            'days' => ['array'],
            'days.*.date' => ['required', 'date_format:Y-m-d'],
            'days.*.label' => ['nullable', 'string', 'max:255'],
        ]);

        return array_values(array_map(
            fn (array $day): array => ['date' => $day['date'], 'label' => $day['label'] ?? null],
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

    /**
     * @return list<array{id: string, name: string}>
     */
    private function brandOptions(): array
    {
        return array_values(Brand::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Brand $brand): array => ['id' => (string) $brand->getKey(), 'name' => $brand->name])
            ->all());
    }
}

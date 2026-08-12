<?php

declare(strict_types=1);

namespace Konventix\Event\Http\Controllers\Admin;

use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Event\Enums\Permission;
use Konventix\Event\Models\Event;
use Konventix\Platform\Slots\SlotRegistry;

/**
 * Operator console: the per-event admin dashboard skeleton (ADR-0029, ADR-0003
 * "slots are an activation gate point").
 *
 * Events are brand-owned (ADR-0010); the admin host hardens `BrandScope` to
 * nothing (ADR-0029), so every query here uses the explicit
 * `withoutBrandScope()` escape hatch deliberately — a single event's ULID
 * already implies which brand it belongs to, and the index legitimately spans
 * every brand the operator runs.
 *
 * The dashboard composes Event's own header with contributions from other
 * modules through Platform's `SlotRegistry`. This controller never queries
 * Registration's or Admission's models — the hard "no cross-module Eloquent
 * imports" rule — it only hands the slot's declared component name, props and
 * the event ULID to the page; each contributing module fetches its own
 * numbers from its own admin endpoint.
 */
class EventAdminController
{
    public function __construct(private readonly SlotRegistry $slots) {}

    public function index(): Response
    {
        Gate::authorize(Permission::ViewEvents->value);

        return Inertia::render('admin/events/Index', [
            'events' => Event::withoutBrandScope()
                ->orderByDesc('starts_at')
                ->get()
                ->map(fn (Event $event): array => [
                    'id' => $event->getKey(),
                    'name' => $event->name,
                    'slug' => $event->slug,
                    'state' => $event->state->value,
                    'startsAt' => $event->starts_at->format('Y-m-d H:i'),
                ])
                ->all(),
        ]);
    }

    public function show(string $event): Response
    {
        Gate::authorize(Permission::ViewEvents->value);

        /** @var Event $model */
        $model = Event::withoutBrandScope()->findOrFail($event);

        return Inertia::render('admin/events/Dashboard', [
            'event' => [
                'id' => $model->getKey(),
                'name' => $model->name,
                'state' => $model->state->value,
                // ADR-0027: always go through the timezone-aware accessor
                // rather than formatting the raw wall-clock column directly
                // — the printed value is numerically identical today, but a
                // future change to the conversion belongs in one place.
                'startsAt' => $model->startsAtInstant()->format('Y-m-d H:i'),
                'endsAt' => $model->endsAtInstant()->format('Y-m-d H:i'),
                'timezone' => $model->timezone,
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
}

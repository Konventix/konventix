<?php

declare(strict_types=1);

namespace Konventix\Event\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Konventix\Event\Models\Event;
use Konventix\Event\Models\EventDay;
use Konventix\Event\Models\Venue;

/**
 * The public event landing route (SPEC.md "Event visibility" — "the public
 * landing route resolved on the brand's own domain"), rendered as an
 * Inertia page from the module's own pages directory — the Slice 1
 * resolution made `.ai/rules/resources.md`'s convention real.
 *
 * Wall-clock schedule prints as promised (ADR-0027): the props carry the
 * organizer's local times plus the zone, never `new Date()`-parsed instants.
 */
final class EventLandingController
{
    public function show(Event $event): Response
    {
        abort_unless($event->isVisibleOnLanding(), 404);

        return Inertia::render('event/Landing', [
            'event' => [
                'name' => $event->name,
                'description' => $event->description,
                'state' => $event->state->value,
                'startsAt' => $event->starts_at->format('Y-m-d H:i'),
                'endsAt' => $event->ends_at->format('Y-m-d H:i'),
                'timezone' => $event->timezone,
            ],
            'days' => $event->days->map(fn (EventDay $day): array => [
                'date' => $day->date->toDateString(),
                'sequence' => $day->sequence,
                'label' => $day->label,
            ])->all(),
            'venues' => $event->venues->map(fn (Venue $venue): array => [
                'name' => $venue->name,
                'addressLine1' => $venue->address_line1,
                'postalCode' => $venue->postal_code,
                'city' => $venue->city,
                'isPrimary' => (bool) $venue->pivot?->getAttribute('is_primary'),
            ])->all(),
        ]);
    }
}

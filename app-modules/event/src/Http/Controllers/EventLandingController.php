<?php

declare(strict_types=1);

namespace Konventix\Event\Http\Controllers;

use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Event\Models\Event;
use Konventix\Event\Models\EventDay;
use Konventix\Event\Models\Venue;
use Konventix\Platform\Slots\SlotRegistry;

/**
 * Event's public surface: the brand's event list and one event's landing page
 * (SPEC.md "Event visibility" — "the public landing route resolved on the
 * brand's own domain"), rendered as Inertia pages from the module's own pages
 * directory — the Slice 1 resolution made `.ai/rules/resources.md`'s
 * convention real.
 *
 * Wall-clock schedule prints as promised (ADR-0027): the props carry the
 * organizer's local times plus the zone, never `new Date()`-parsed instants.
 */
final class EventLandingController
{
    public function __construct(private readonly SlotRegistry $slots) {}

    /**
     * The brand's public event list — what the topbar's "Events" entry points
     * at, and the only enumeration of events a visitor gets.
     *
     * Upcoming and past are split here rather than in the page because the
     * split needs real instants: `starts_at`/`ends_at` are wall-clock values
     * whose meaning depends on each event's own zone (ADR-0027), so two
     * events an hour apart on the wall can be a day apart in fact. The
     * database can only order the wall-clock strings, which is why the
     * partition happens in PHP, over `endsAtInstant()`.
     */
    public function index(): Response
    {
        $now = Carbon::now();

        [$past, $upcoming] = Event::query()
            ->listedPublicly()
            ->with('venues')
            ->orderBy('starts_at')
            ->get()
            ->partition(fn (Event $event): bool => $event->endsAtInstant()->lessThan($now));

        return Inertia::render('event/Index', [
            'upcoming' => $upcoming->values()->map($this->summarize(...))->all(),
            // Most recent first: a visitor looking back wants last year, not
            // the first edition ever.
            'past' => $past->sortByDesc(fn (Event $event): Carbon => $event->startsAtInstant())
                ->values()
                ->map($this->summarize(...))
                ->all(),
        ]);
    }

    /**
     * @return array{slug: string, name: string, description: string|null, state: string, startsAt: string, endsAt: string, timezone: string, venue: string|null}
     */
    private function summarize(Event $event): array
    {
        $primary = $event->primaryVenue() ?? $event->venues->first();

        return [
            'slug' => $event->slug,
            'name' => $event->name,
            'description' => $event->description,
            'state' => $event->state->value,
            'startsAt' => $event->starts_at->format('Y-m-d H:i'),
            'endsAt' => $event->ends_at->format('Y-m-d H:i'),
            'timezone' => $event->timezone,
            'venue' => $primary?->city,
        ];
    }

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
            // What a visitor can actually *do* here. Event owns none of it
            // and knows none of it: Registration contributes the offers
            // through the `event.landing` slot, and the slot applies the
            // per-brand activation gate (ADR-0003 point 3) on the way out.
            // Registration is Core and so always active, but the gate is what
            // lets a gateable module — Competition's schedule, Staffing's
            // call for volunteers — join this page later without Event
            // learning it exists.
            'slots' => $this->slots->for('event.landing', ['eventId' => $event->getKey()])
                ->map(fn (array $entry): array => [
                    'module' => $entry['module'],
                    'component' => $entry['component'],
                    'props' => $entry['props'],
                ])
                ->all(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Event\Http\Controllers;

use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Event\Models\Event;
use Konventix\Event\Models\EventDay;
use Konventix\Event\Models\Venue;
use Konventix\Platform\Brands\BrandUrl;
use Konventix\Platform\Calendar\CalendarFile;
use Konventix\Platform\Models\Brand;
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
    public function __construct(
        private readonly SlotRegistry $slots,
        private readonly BrandUrl $brandUrl,
    ) {}

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

    /**
     * Spec §6.2: public, no authentication — the only gate is the same
     * visibility rule `show()` enforces, so a draft event's calendar file
     * is as unreachable as its landing page.
     *
     * `LOCATION` carries the primary venue's name and full address (§6.1's
     * original wording): unlike Task 32's mail attachment, this route
     * reads Event's own `Venue` rows directly rather than a read-model
     * projection off `VenueAssigned`, so it is not limited to name-and-city
     * (§13's 2026-09-11 venue amendment covers the projection, not this).
     */
    public function calendar(Event $event): HttpResponse
    {
        abort_unless($event->isVisibleOnLanding(), 404);

        $venue = $event->primaryVenue() ?? $event->venues->first();

        $ics = CalendarFile::forEvent(
            uid: $event->getKey().'@'.$this->hostFor($event->brand),
            summary: $event->name,
            description: $event->description,
            location: $venue === null
                ? null
                : sprintf('%s, %s, %s %s', $venue->name, $venue->address_line1, $venue->postal_code, $venue->city),
            url: route('event.show', ['event' => $event->slug]),
            startsAt: $event->startsAtInstant(),
            endsAt: $event->endsAtInstant(),
            lastModifiedAt: $event->updated_at,
        );

        return response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$event->slug.'.ics"',
        ]);
    }

    /**
     * `CalendarFile::forEvent()`'s `UID` is the event id plus the brand's
     * host (spec §6.1), so re-importing updates one entry rather than
     * duplicating it. Reads the brand's *primary* domain through
     * `BrandUrl` — the same service every other cross-module link in this
     * codebase uses to reach a brand's host from outside a request —
     * rather than `request()->getHost()`, so the identifier is the same
     * across a brand's several domains (apex, www) and across two visits
     * on different ones. Falls back to `APP_URL`'s host on a brand with no
     * domain configured yet, mirroring `RegistrationMailer::brandAbsolute()`'s
     * own fallback.
     */
    private function hostFor(Brand $brand): string
    {
        $origin = $this->brandUrl->baseFor($brand);

        return $origin === null
            ? (string) parse_url((string) config('app.url'), PHP_URL_HOST)
            : (string) parse_url($origin, PHP_URL_HOST);
    }
}

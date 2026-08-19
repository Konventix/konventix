<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Konventix\Event\Actions\AssignVenue;
use Konventix\Event\Actions\ConcludeEvent;
use Konventix\Event\Actions\CreateEvent;
use Konventix\Event\Actions\CreateVenue;
use Konventix\Event\Actions\PublishEvent;
use Konventix\Event\Actions\StartEvent;
use Konventix\Event\Data\EventData;
use Konventix\Event\Data\VenueData;
use Konventix\Event\Enums\EventLifecycleState;
use Konventix\Event\Enums\EventVisibility;
use Konventix\Event\Enums\ReentryPolicy;
use Konventix\Event\Models\Event;
use Konventix\Event\Models\Venue;
use Konventix\Platform\Models\Brand;

/**
 * The brand's venues and events, created through the real lifecycle Actions
 * rather than the factory. That is the difference between a demo that looks
 * right and one that behaves right: `CreateEvent` emits `EventCreated`, which
 * is what projects Resources' event window (nothing can be booked without
 * one) and Admission's entrance profile, and `PublishEvent`/`ConcludeEvent`
 * emit the events Compliance's retention clocks start from.
 *
 * Each brand ends up with the requested number of live events plus two the
 * lifecycle needs to be legible: last year's concluded edition (the archive
 * view, and a started retention clock) and a far-future draft (the
 * unpublished state). Only the live ones carry registrations.
 *
 * The first live edition is deliberately *running*, with today inside its
 * day calendar — that is what lets the entrance slice record real scans
 * against the real clock instead of pretending.
 *
 * Idempotent per event slug, like every other slice.
 */
final class DemoEventSeeder
{
    /** Three days, so multi-day admission and check-in have something to bind to. */
    private const int DAYS_PER_EVENT = 3;

    public function __construct(
        private readonly CreateVenue $createVenue,
        private readonly CreateEvent $createEvent,
        private readonly PublishEvent $publishEvent,
        private readonly StartEvent $startEvent,
        private readonly ConcludeEvent $concludeEvent,
        private readonly AssignVenue $assignVenue,
    ) {}

    /**
     * The live events, in start order. The concluded and draft editions are
     * seeded too but not returned: nothing else hangs off them.
     *
     * @return Collection<int, Event>
     */
    public function handle(Brand $brand, int $eventCount): Collection
    {
        $venues = $this->seedVenues($brand);

        $live = collect(range(1, max(1, $eventCount)))
            ->map(fn (int $index): Event => $this->seedLiveEvent($brand, $venues, $index))
            ->sortBy(fn (Event $event): string => $event->starts_at->toDateTimeString())
            ->values();

        $this->seedConcludedEvent($brand, $venues);
        $this->seedDraftEvent($brand, $venues);

        return $live;
    }

    /**
     * Two venues per brand: the main hall every event is assigned to, and a
     * second one so the venue picker has a choice to make.
     *
     * @return Collection<int, Venue>
     */
    private function seedVenues(Brand $brand): Collection
    {
        $definitions = [
            [
                'name' => "{$brand->name} Messehalle",
                'addressLine1' => 'Messeplatz 1',
                'postalCode' => '30521',
                'city' => 'Hannover',
                'accessNotes' => 'Loading bay on the north side, open from 06:00.',
            ],
            [
                'name' => "{$brand->name} Kulturzentrum",
                'addressLine1' => 'Bahnhofstraße 12',
                'postalCode' => '20095',
                'city' => 'Hamburg',
                'accessNotes' => null,
            ],
        ];

        return collect($definitions)->map(function (array $definition) use ($brand): Venue {
            $existing = Venue::query()->firstWhere('name', $definition['name']);

            if ($existing !== null) {
                return $existing;
            }

            return $this->createVenue->handle($brand->getKey(), new VenueData(
                name: $definition['name'],
                addressLine1: $definition['addressLine1'],
                addressLine2: null,
                postalCode: $definition['postalCode'],
                city: $definition['city'],
                countryCode: 'DE',
                latitude: null,
                longitude: null,
                accessNotes: $definition['accessNotes'],
            ));
        });
    }

    /**
     * @param  Collection<int, Venue>  $venues
     */
    private function seedLiveEvent(Brand $brand, Collection $venues, int $index): Event
    {
        $year = now()->year + ($index - 1);
        $isCurrent = $index === 1;

        // The first edition is happening *right now* — doors opened
        // yesterday. That is not decoration: an admission token is only valid
        // inside its signing key's window, and a scan only counts on one of
        // the event's days (D-ADM-04), so an entrance log can only be seeded
        // honestly for an event whose days include today. It is also the
        // state an operator console exists for.
        $startsAt = $isCurrent
            ? now()->subDay()->startOfDay()->setTime(10, 0)
            : now()->addMonths(($index - 1) * 12)->startOfDay()->setTime(10, 0);

        $event = $this->createOrFind(
            $brand,
            name: "{$brand->name} {$year}",
            slug: Str::slug($brand->name).'-'.$year,
            startsAt: $startsAt,
            description: "The {$year} edition — three days of {$brand->name}.",
        );

        if ($event->state === EventLifecycleState::Draft) {
            $event = $this->publishEvent->handle($event);
        }

        if ($isCurrent && $event->state === EventLifecycleState::Published) {
            $event = $this->startEvent->handle($event);
        }

        $this->assignPrimaryVenue($event, $venues);

        return $event;
    }

    /**
     * Last year's edition, taken all the way to `concluded` — the state
     * Compliance's `EventConcluded` retention clock starts from, and the one
     * the archive filter exists for.
     *
     * @param  Collection<int, Venue>  $venues
     */
    private function seedConcludedEvent(Brand $brand, Collection $venues): void
    {
        $year = now()->year - 1;

        $event = $this->createOrFind(
            $brand,
            name: "{$brand->name} {$year}",
            slug: Str::slug($brand->name).'-'.$year,
            startsAt: now()->subMonths(9)->startOfDay()->setTime(10, 0),
            description: "The {$year} edition — wrapped, archived, and remembered fondly.",
        );

        $this->assignPrimaryVenue($event, $venues);

        if ($event->state === EventLifecycleState::Draft) {
            $event = $this->publishEvent->handle($event);
        }

        if ($event->state === EventLifecycleState::Published) {
            $event = $this->startEvent->handle($event);
        }

        if ($event->state === EventLifecycleState::Running) {
            $this->concludeEvent->handle($event);
        }
    }

    /**
     * Next year's edition, left in `draft` — an unpublished event is absent
     * from the public site but present in the console, which is the whole
     * point of the visibility split.
     *
     * @param  Collection<int, Venue>  $venues
     */
    private function seedDraftEvent(Brand $brand, Collection $venues): void
    {
        $year = now()->year + 5;

        $event = $this->createOrFind(
            $brand,
            name: "{$brand->name} {$year}",
            slug: Str::slug($brand->name).'-'.$year,
            startsAt: now()->addYears(5)->startOfDay()->setTime(10, 0),
            description: 'Pencilled in. Nothing announced yet.',
            visibility: EventVisibility::Unlisted,
        );

        // The second venue, so the draft edition is not a copy of the
        // live one down to the address.
        $alternate = $venues->skip(1)->values();

        $this->assignPrimaryVenue($event, $alternate->isEmpty() ? $venues : $alternate);
    }

    private function createOrFind(
        Brand $brand,
        string $name,
        string $slug,
        CarbonInterface $startsAt,
        string $description,
        EventVisibility $visibility = EventVisibility::Public,
    ): Event {
        $existing = Event::query()->firstWhere('slug', $slug);

        if ($existing !== null) {
            return $existing;
        }

        $endsAt = $startsAt->copy()->addDays(self::DAYS_PER_EVENT - 1)->setTime(18, 0);

        return $this->createEvent->handle(
            $brand->getKey(),
            new EventData(
                name: $name,
                slug: $slug,
                description: $description,
                startsAt: $startsAt->format('Y-m-d H:i:s'),
                endsAt: $endsAt->format('Y-m-d H:i:s'),
                timezone: 'Europe/Berlin',
                visibility: $visibility,
                reentryPolicy: ReentryPolicy::Unlimited,
            ),
            $this->days($startsAt),
        );
    }

    /**
     * One row per calendar day, labelled the way a programme would be — the
     * day set Admission binds per-day validity and check-ins to.
     *
     * @return list<array{date: string, label: string|null}>
     */
    private function days(CarbonInterface $startsAt): array
    {
        $labels = ['Opening day', 'Main day', 'Finals & teardown'];

        return array_map(
            fn (int $offset): array => [
                'date' => $startsAt->copy()->addDays($offset)->toDateString(),
                'label' => $labels[$offset],
            ],
            range(0, self::DAYS_PER_EVENT - 1),
        );
    }

    /**
     * @param  Collection<int, Venue>  $venues
     */
    private function assignPrimaryVenue(Event $event, Collection $venues): void
    {
        /** @var Venue|null $venue */
        $venue = $venues->first();

        if ($venue === null || $event->venues()->whereKey($venue->getKey())->exists()) {
            return;
        }

        $this->assignVenue->handle($event, $venue, isPrimary: true);
    }
}

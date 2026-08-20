<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Konventix\Event\Models\Event;
use Konventix\Resources\Actions\ConfirmResourceBooking;
use Konventix\Resources\Actions\HoldResourceBooking;
use Konventix\Resources\Enums\CapacityMode;
use Konventix\Resources\Models\Resource;
use Konventix\Resources\Models\ResourceBooking;
use Konventix\Resources\Models\ResourceEventWindow;
use Konventix\Resources\Models\ResourceType;

/**
 * The bookable inventory the archetype declares: a LAN's seat grid, a fair's
 * booth rows and meeting rooms, and a quantity-mode locker bank in both —
 * every catalogue carries each capacity mode (ADR-0034), because the two are
 * enforced by different mechanisms and a demo that only shows `unit` hides
 * half the module.
 *
 * The catalogue is seeded *before* registrations on purpose. A confirmed
 * registration whose type grants a `resource_claim` books its resource
 * through `FulfilResourceClaims`, and that fails soft when the type does not
 * exist yet — the exhibitor would register fine and silently get no booth.
 *
 * Bookings that do not come from a claim (the seats) are placed through the
 * real hold/confirm pair rather than the factory, so the demo carries the
 * interval-overlap guard's actual output.
 *
 * Idempotent per type slug and per holder.
 */
final class DemoResourcesSeeder
{
    public function __construct(
        private readonly HoldResourceBooking $hold,
        private readonly ConfirmResourceBooking $confirm,
    ) {}

    /**
     * The claimed type — a booth in both archetypes — which the trade
     * registration type grants a claim on. Everything else in the catalogue
     * is seeded too but nothing references it by id: those exist to be booked
     * from the plan.
     */
    public function seedCatalogue(Event $event, EventArchetype $archetype): ResourceType
    {
        $types = [];

        foreach ($archetype->resourceCatalogue() as $definition) {
            $type = $this->resourceType(
                $event,
                $definition['name'],
                $definition['mode'],
                holdMinutes: $definition['holdMinutes'],
            );

            $this->seedUnits(
                $type,
                $definition['count'],
                $this->labeller($definition['name'], $definition['labels']),
                capacity: $definition['capacity'],
            );

            $types[$definition['name']] = $type;
        }

        // Every catalogue carries the claimed type; the archetype enum is
        // what guarantees it, so a missing one is a programming error rather
        // than a state the seed should paper over.
        return $types[$archetype->claimedResourceTypeName()];
    }

    /**
     * How a type's units are named. A seat grid reads as rows and numbers, a
     * booth row as a running count, and a quantity-mode row is a single
     * bank whose capacity is the interesting number.
     *
     * @return callable(int): string
     */
    private function labeller(string $name, string $style): callable
    {
        return match ($style) {
            'grid' => fn (int $index): string => sprintf(
                '%s-%02d',
                $this->rowLetter(intdiv($index - 1, 8)),
                (($index - 1) % 8) + 1,
            ),
            'bank' => fn (): string => $name.' bank',
            default => fn (int $index): string => $name.' '.$index,
        };
    }

    /**
     * The row letter for a seat grid line. Wraps at `Z` rather than walking
     * off the end of the alphabet into control characters, so the label stays
     * a label whatever the catalogue's seat count is.
     */
    private function rowLetter(int $row): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

        return $alphabet[$row % strlen($alphabet)];
    }

    /**
     * A handful of bookings on the archetype's floor plan — seats at a LAN,
     * meeting rooms at a fair — held by real registrations, so the plan is
     * not uniformly free. Confirmed and held are both represented: a held
     * booking is what the hold-expiry sweep exists for.
     *
     * @param  Collection<int, string>  $registrationIds
     */
    public function seedFloorPlanBookings(Event $event, Collection $registrationIds, EventArchetype $archetype): void
    {
        $window = ResourceEventWindow::forEvent($event->brand_id, $event->getKey());

        if ($window === null || $registrationIds->isEmpty()) {
            return;
        }

        $type = ResourceType::query()->firstWhere(
            'slug',
            $this->slug($event, $archetype->floorPlanResourceTypeName()),
        );

        if ($type === null) {
            return;
        }

        $free = Resource::query()
            ->where('resource_type_id', $type->getKey())
            ->orderBy('label')
            ->get()
            ->reject(fn (Resource $resource): bool => ResourceBooking::query()
                ->where('resource_id', $resource->getKey())
                ->exists())
            ->values();

        $holders = $registrationIds->values();

        $free->take($holders->count())->each(
            function (Resource $bookable, int $index) use ($holders, $window): void {
                $registrationId = (string) $holders->get($index);

                $booking = $this->hold->handle(
                    resource: $bookable,
                    holderType: ResourceBooking::HOLDER_REGISTRATION,
                    holderId: $registrationId,
                    startsAt: $window->starts_at,
                    endsAt: $window->ends_at,
                    registrationId: $registrationId,
                );

                // Every third booking is left on hold, so the plan shows
                // both states and the expiry sweep has something to find.
                if ($index % 3 !== 2) {
                    $this->confirm->handle($booking);
                }
            },
        );
    }

    private function resourceType(Event $event, string $name, CapacityMode $mode, int $holdMinutes): ResourceType
    {
        $slug = $this->slug($event, $name);

        $existing = ResourceType::query()->firstWhere('slug', $slug);

        if ($existing !== null) {
            return $existing;
        }

        return ResourceType::factory()
            ->forEvent($event->getKey())
            ->create([
                'name' => $name,
                'slug' => $slug,
                'mode' => $mode,
                'hold_minutes' => $holdMinutes,
            ]);
    }

    /**
     * @param  callable(int): string  $label
     */
    private function seedUnits(ResourceType $type, int $count, callable $label, int $capacity = 1): void
    {
        $existing = Resource::query()->where('resource_type_id', $type->getKey())->count();

        for ($index = $existing + 1; $index <= $count; $index++) {
            Resource::factory()->ofType($type)->create([
                'label' => $label($index),
                'capacity' => $capacity,
            ]);
        }
    }

    private function slug(Event $event, string $name): string
    {
        return Str::slug($event->slug.'-'.$name);
    }
}

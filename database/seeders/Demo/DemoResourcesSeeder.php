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
 * The bookable inventory: seats on the floor, exhibitor booths, and a
 * quantity-mode locker bank — one of each capacity mode (ADR-0034), because
 * the two are enforced by different mechanisms and a demo that only shows
 * `unit` hides half the module.
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
    private const int SEAT_COUNT = 24;

    private const int BOOTH_COUNT = 6;

    public function __construct(
        private readonly HoldResourceBooking $hold,
        private readonly ConfirmResourceBooking $confirm,
    ) {}

    /**
     * The booth type, which the exhibitor registration type grants a claim
     * on. Seats and lockers are seeded too but nothing references them by
     * id — they exist to be booked from the plan.
     */
    public function seedCatalogue(Event $event): ResourceType
    {
        $seats = $this->resourceType($event, 'Seat', CapacityMode::Unit, holdMinutes: 20);
        $this->seedUnits($seats, self::SEAT_COUNT, fn (int $index): string => sprintf(
            '%s-%02d',
            $this->rowLetter(intdiv($index - 1, 8)),
            (($index - 1) % 8) + 1,
        ));

        $booths = $this->resourceType($event, 'Booth', CapacityMode::Unit, holdMinutes: 60);
        $this->seedUnits($booths, self::BOOTH_COUNT, fn (int $index): string => 'Booth '.$index);

        // Quantity mode: one row carrying capacity, guarded by a summed
        // overlap check rather than by exclusivity.
        $lockers = $this->resourceType($event, 'Locker', CapacityMode::Quantity, holdMinutes: 30);
        $this->seedUnits($lockers, 1, fn (): string => 'Locker bank', capacity: 50);

        return $booths;
    }

    /**
     * The row letter for a seat grid line. Wraps at `Z` rather than walking
     * off the end of the alphabet into control characters, so the label stays
     * a label whatever `SEAT_COUNT` is set to.
     */
    private function rowLetter(int $row): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

        return $alphabet[$row % strlen($alphabet)];
    }

    /**
     * A handful of seat bookings for real registrations, so the plan is not
     * uniformly free. Confirmed and held are both represented: a held
     * booking is what the hold-expiry sweep exists for.
     *
     * @param  Collection<int, string>  $registrationIds
     */
    public function seedSeatBookings(Event $event, Collection $registrationIds): void
    {
        $window = ResourceEventWindow::forEvent($event->brand_id, $event->getKey());

        if ($window === null || $registrationIds->isEmpty()) {
            return;
        }

        $type = ResourceType::query()->firstWhere('slug', $this->slug($event, 'Seat'));

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
            function (Resource $seat, int $index) use ($holders, $window): void {
                $registrationId = (string) $holders->get($index);

                $booking = $this->hold->handle(
                    resource: $seat,
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

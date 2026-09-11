<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Konventix\Event\Models\Event;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Models\Registration;
use Konventix\Resources\Actions\ConfirmResourceBooking;
use Konventix\Resources\Actions\CreatePlan;
use Konventix\Resources\Actions\CreateSeatClaims;
use Konventix\Resources\Actions\HoldResourceBooking;
use Konventix\Resources\Data\PlanData;
use Konventix\Resources\Enums\CapacityMode;
use Konventix\Resources\Enums\SeatingMode;
use Konventix\Resources\Models\Plan;
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
        private readonly CreatePlan $createPlan,
        private readonly CreateSeatClaims $createSeatClaims,
    ) {}

    /**
     * Every type the archetype declares, keyed by name — the claimed type (a
     * booth in both archetypes, which the trade registration type grants a
     * claim on) and the floor-plan type (seats at a LAN, meeting rooms at a
     * fair) both come out of this map by name (`claimedResourceTypeName()`,
     * `floorPlanResourceTypeName()`) rather than two separate return values.
     *
     * @return array<string, ResourceType>
     */
    public function seedCatalogue(Event $event, EventArchetype $archetype): array
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

        return $types;
    }

    /**
     * One hall plan per event (design §4.4), through the real `CreatePlan`
     * Action rather than a factory row — an empty `konventix.plan/v1`
     * document, no places yet. The operator console's plan screens
     * (`admin.plans.edit`/`.download`/`.occupancy`) otherwise have nothing
     * seeded to open: a genuine seed gap the sweep in `DryRunTest` caught,
     * since a console screen nobody can reach is worth knowing about. This
     * plan is deliberately disconnected from the catalogue above — the seat
     * and booth inventory is still seeded straight into `resources` (see the
     * class docblock) — so it stays a minimal, honest "awaiting layout" plan
     * rather than one pretending to describe the floor the seats live on.
     */
    public function seedPlan(Event $event): Plan
    {
        $existing = Plan::query()->where('event_id', $event->getKey())->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->createPlan->handle(
            $event->brand_id,
            new PlanData(
                eventId: $event->getKey(),
                name: 'Floor plan',
                floorLabel: null,
                widthMm: 20000,
                heightMm: 15000,
            ),
            null,
        );
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

    /**
     * A genuine, unplaced seat claim for every confirmed registration of the
     * event, against the floor-plan resource type (seats at a LAN, meeting
     * rooms at a fair) — through the real `CreateSeatClaims` Action, the same
     * one `FulfilClaimsOnRegistrationConfirmed` calls for a real `seating`
     * grant. None of the seeded registration types carries that grant
     * (#282 tracks giving one its own), so without this a registrant's own
     * seat-picker screen 404s on "no claim" — the ownership rule working
     * exactly as designed, on data that never gave anyone anything to own.
     *
     * Every confirmed registration gets one, not just one type's, because
     * which registrant `DemoComplianceSeeder`'s account-screen walk logs in
     * as is picked by registration-independent criteria (it prefers payers,
     * GitLab #185) — a claim on only one type would leave the picker
     * reachable by luck of the draw rather than by design. Queried fresh
     * rather than handed a registration list: Commerce's own priced type
     * confirms registrants after this event's plain catalogue does (see
     * `SeedDemoDataCommand::seedEvent()`), so a snapshot taken earlier would
     * miss them.
     *
     * Claims never touch booking inventory — `CreateSeatClaims` only records
     * the entitlement, and a booking happens only once something is picked —
     * so this cannot conflict with the direct holds `seedFloorPlanBookings`
     * already places on the same resource type.
     */
    public function seedSeatClaims(Event $event, EventArchetype $archetype): void
    {
        $type = ResourceType::query()->firstWhere(
            'slug',
            $this->slug($event, $archetype->floorPlanResourceTypeName()),
        );

        if ($type === null) {
            return;
        }

        Registration::query()
            ->where('event_id', $event->getKey())
            ->where('status', RegistrationStatus::Confirmed)
            ->whereNotNull('registrant_user_id')
            ->get(['id', 'registrant_user_id'])
            ->each(function (Registration $registration) use ($event, $type): void {
                $this->createSeatClaims->handle(
                    registrationId: (string) $registration->getKey(),
                    eventId: $event->getKey(),
                    brandId: $event->brand_id,
                    registrantUserId: $registration->registrant_user_id,
                    grants: [[
                        'kind' => 'seating',
                        'role' => 'seat',
                        'quantity' => 1,
                        'resource_type_id' => null,
                        'resource_type_ids' => [$type->getKey()],
                        'seating_mode' => SeatingMode::Pick->value,
                    ]],
                );
            });
    }
}

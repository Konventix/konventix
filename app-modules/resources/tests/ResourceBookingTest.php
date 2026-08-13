<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Konventix\Resources\Actions\ConfirmResourceBooking;
use Konventix\Resources\Actions\HoldResourceBooking;
use Konventix\Resources\Actions\ReleaseResourceBooking;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Events\ResourceBookingConfirmed;
use Konventix\Resources\Events\ResourceBookingHeld;
use Konventix\Resources\Events\ResourceBookingReleased;
use Konventix\Resources\Exceptions\ResourceUnavailableException;
use Konventix\Resources\Models\Resource;
use Konventix\Resources\Models\ResourceBooking;
use Konventix\Resources\Models\ResourceType;

use function Pest\Laravel\travelTo;

/**
 * Half-open interval bookings (ADR-0020) in both capacity modes (ADR-0034).
 *
 * The point of these tests is the *mechanism*, not just the outcome: unit
 * mode must be stopped by the database, because ADR-0020 rejected
 * application-level checks on the grounds that they lose races. A test that
 * only asserted "the second booking failed" would pass just as well against
 * the design the ADR rejected.
 *
 * Helper names prefixed — Pest helpers are process-global (.ai/rules/tests.md).
 */
function bookingTestUnitResource(): Resource
{
    return Resource::factory()->ofType(ResourceType::factory()->create())->create();
}

function bookingTestHold(Resource $resource, int $hoursFromNow, int $lengthHours = 2, int $quantity = 1): ResourceBooking
{
    return app(HoldResourceBooking::class)->handle(
        resource: $resource,
        holderType: ResourceBooking::HOLDER_REGISTRATION,
        holderId: (string) Str::ulid(),
        startsAt: now()->startOfHour()->addHours($hoursFromNow),
        endsAt: now()->startOfHour()->addHours($hoursFromNow + $lengthHours),
        quantity: $quantity,
    );
}

it('holds a resource and emits the provisional event', function (): void {
    $this->withBrand();

    Event::fake([ResourceBookingHeld::class]);

    $booking = bookingTestHold(bookingTestUnitResource(), 1);

    expect($booking->state)->toBe(BookingState::Held)
        ->and($booking->hold_expires_at)->not->toBeNull();

    Event::assertDispatched(ResourceBookingHeld::class);
    // A hold must not masquerade as a firm booking (SPEC: it "must never
    // reach a badge").
    Event::assertNotDispatched(ResourceBookingConfirmed::class);
});

it('refuses an overlapping booking through the database constraint', function (): void {
    $this->withBrand();

    $resource = bookingTestUnitResource();
    bookingTestHold($resource, 1);

    expect(fn () => bookingTestHold($resource, 2))
        ->toThrow(ResourceUnavailableException::class);
});

it('is the database, not the Action, that refuses the overlap', function (): void {
    // ADR-0020 rejected application-level checks because they lose races.
    // Writing straight past the Action must still be impossible.
    $this->withBrand();

    $resource = bookingTestUnitResource();
    bookingTestHold($resource, 1);

    $direct = new ResourceBooking([
        'resource_id' => $resource->id,
        'holder_type' => ResourceBooking::HOLDER_REGISTRATION,
        'holder_id' => (string) Str::ulid(),
        'quantity' => 1,
        'state' => BookingState::Confirmed,
        'starts_at' => now()->startOfHour()->addHours(2),
        'ends_at' => now()->startOfHour()->addHours(4),
    ]);
    $direct->forceFill(['brand_id' => $resource->brand_id]);

    expect(fn () => $direct->save())->toThrow(QueryException::class);
});

it('allows a booking that starts exactly when another ends', function (): void {
    // The half-open interval is the whole reason ADR-0020 chose `[)`: back-to-
    // back slots are not a conflict, and an inclusive range would make them one.
    $this->withBrand();

    $resource = bookingTestUnitResource();

    $first = bookingTestHold($resource, 1, lengthHours: 1);
    $second = bookingTestHold($resource, 2, lengthHours: 1);

    expect($first->ends_at->equalTo($second->starts_at))->toBeTrue()
        ->and($second->state)->toBe(BookingState::Held);
});

it('frees the interval again once a booking is released', function (): void {
    // The constraint is partial over held/confirmed, so releasing gives the
    // slot back without deleting who had it.
    $this->withBrand();

    $resource = bookingTestUnitResource();
    $first = bookingTestHold($resource, 1);

    app(ReleaseResourceBooking::class)->handle($first);

    $second = bookingTestHold($resource, 1);

    expect($second->state)->toBe(BookingState::Held)
        ->and($first->fresh()->state)->toBe(BookingState::Released)
        // The history survives.
        ->and(ResourceBooking::query()->where('resource_id', $resource->id)->count())->toBe(2);
});

it('confirms a hold and emits the event other modules act on', function (): void {
    $this->withBrand();

    $booking = bookingTestHold(bookingTestUnitResource(), 1);

    Event::fake([ResourceBookingConfirmed::class]);

    $confirmed = app(ConfirmResourceBooking::class)->handle($booking);

    expect($confirmed->state)->toBe(BookingState::Confirmed)
        ->and($confirmed->hold_expires_at)->toBeNull();

    Event::assertDispatched(ResourceBookingConfirmed::class);
});

it('refuses to confirm an expired hold', function (): void {
    // Its interval may already belong to someone else.
    $this->withBrand();

    $booking = bookingTestHold(bookingTestUnitResource(), 1);

    travelTo(now()->addHours(2));

    expect(fn () => app(ConfirmResourceBooking::class)->handle($booking))
        ->toThrow(RuntimeException::class);
});

it('is idempotent when releasing twice', function (): void {
    $this->withBrand();

    $booking = bookingTestHold(bookingTestUnitResource(), 1);

    app(ReleaseResourceBooking::class)->handle($booking);

    Event::fake([ResourceBookingReleased::class]);

    app(ReleaseResourceBooking::class)->handle($booking->fresh());

    // A cancellation arriving twice — a click plus a queued job — is normal.
    Event::assertNotDispatched(ResourceBookingReleased::class);
});

it('draws down capacity in quantity mode rather than blocking the resource', function (): void {
    // ADR-0034: one row, a capacity, and bookings that sum against it.
    $this->withBrand();

    $type = ResourceType::factory()->quantityMode()->create();
    $resource = Resource::factory()->ofType($type)->withCapacity(10)->create();

    $first = bookingTestHold($resource, 1, quantity: 4);
    $second = bookingTestHold($resource, 1, quantity: 6);

    expect($first->state)->toBe(BookingState::Held)
        ->and($second->state)->toBe(BookingState::Held);
});

it('refuses a quantity booking that would exceed capacity', function (): void {
    $this->withBrand();

    $type = ResourceType::factory()->quantityMode()->create();
    $resource = Resource::factory()->ofType($type)->withCapacity(10)->create();

    bookingTestHold($resource, 1, quantity: 8);

    expect(fn () => bookingTestHold($resource, 1, quantity: 3))
        ->toThrow(ResourceUnavailableException::class);
});

it('counts capacity per overlapping interval, not per resource overall', function (): void {
    $this->withBrand();

    $type = ResourceType::factory()->quantityMode()->create();
    $resource = Resource::factory()->ofType($type)->withCapacity(10)->create();

    bookingTestHold($resource, 1, lengthHours: 1, quantity: 10);

    // A later, non-overlapping window is entirely free again.
    expect(bookingTestHold($resource, 2, lengthHours: 1, quantity: 10)->state)
        ->toBe(BookingState::Held);
});

it('releases capacity back in quantity mode', function (): void {
    $this->withBrand();

    $type = ResourceType::factory()->quantityMode()->create();
    $resource = Resource::factory()->ofType($type)->withCapacity(10)->create();

    $held = bookingTestHold($resource, 1, quantity: 10);
    app(ReleaseResourceBooking::class)->handle($held);

    expect(bookingTestHold($resource, 1, quantity: 10)->state)->toBe(BookingState::Held);
});

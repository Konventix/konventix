<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Resources\Actions\ConfirmResourceBooking;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Events\ResourceBookingReleased;
use Konventix\Resources\Models\ResourceBooking;

/**
 * Cancellation and rejection release every active booking of the
 * registration (SPEC.md "Consumes", design doc §7.2). Ungated on purpose —
 * ADR-0060's explicit ADR-0003 exception: a release is safe and idempotent
 * for a brand that switched the module off, and a seat that stays booked
 * for a cancelled registration is the failure #105 reported.
 */
function seatingCancellationFixture(): array
{
    $brand = test()->withBrand();
    $eventId = (string) Str::ulid();
    $window = seatingProjectWindow($eventId, $brand->id);
    $type = seatingUnitType($eventId);
    $registrationId = (string) Str::ulid();

    $held = seatingHold(seatingPublishedResource($type, 'A-01'), $window, $registrationId);
    $confirmed = app(ConfirmResourceBooking::class)->handle(
        seatingHold(seatingPublishedResource($type, 'A-02'), $window, $registrationId),
    );
    $other = seatingHold(seatingPublishedResource($type, 'A-03'), $window, (string) Str::ulid());

    return [$brand, $registrationId, $held, $confirmed, $other];
}

it('releases held and confirmed bookings of a cancelled registration with the cancellation reason', function (): void {
    [$brand, $registrationId, $held, $confirmed, $other] = seatingCancellationFixture();

    Event::fake([ResourceBookingReleased::class]);

    seatingCancelRegistration($registrationId);

    expect($held->fresh()->state)->toBe(BookingState::Cancelled)
        ->and($confirmed->fresh()->state)->toBe(BookingState::Cancelled)
        ->and($other->fresh()->state)->toBe(BookingState::Held);

    Event::assertDispatchedTimes(ResourceBookingReleased::class, 2);
    Event::assertDispatched(
        ResourceBookingReleased::class,
        fn (ResourceBookingReleased $e): bool => $e->reason === 'registration_cancelled'
            && $e->registrationId === $registrationId
            && $e->brandId === $brand->id,
    );
});

it('releases on rejection with its own reason', function (): void {
    [, $registrationId, $held, $confirmed] = seatingCancellationFixture();

    Event::fake([ResourceBookingReleased::class]);

    seatingRejectRegistration($registrationId);

    expect($held->fresh()->state)->toBe(BookingState::Cancelled)
        ->and($confirmed->fresh()->state)->toBe(BookingState::Cancelled);

    Event::assertDispatched(
        ResourceBookingReleased::class,
        fn (ResourceBookingReleased $e): bool => $e->reason === 'registration_rejected',
    );
});

it('is a no-op for a registration it never booked for, and on a second cancellation', function (): void {
    [, $registrationId] = seatingCancellationFixture();

    seatingCancelRegistration($registrationId);

    Event::fake([ResourceBookingReleased::class]);

    seatingCancelRegistration((string) Str::ulid());
    seatingCancelRegistration($registrationId);

    Event::assertNotDispatched(ResourceBookingReleased::class);
});

it('releases even while the module is off for the brand and with no brand in context', function (): void {
    // Neither `activateModule('resources')` nor an ambient brand: the
    // cancellation of a paid order arrives from a webhook.
    [, $registrationId, $held, $confirmed] = seatingCancellationFixture();

    app(BrandContext::class)->forget();

    seatingCancelRegistration($registrationId);

    expect(ResourceBooking::withoutBrandScope()->whereKey($held->id)->value('state'))->toBe(BookingState::Cancelled)
        ->and(ResourceBooking::withoutBrandScope()->whereKey($confirmed->id)->value('state'))->toBe(BookingState::Cancelled)
        ->and(app(BrandContext::class)->current())->toBeNull();
});

it('sets the brand context from the booking while releasing, so downstream listeners see it', function (): void {
    [$brand, $registrationId] = seatingCancellationFixture();

    $seen = [];
    Event::listen(ResourceBookingReleased::class, function () use (&$seen): void {
        $seen[] = app(BrandContext::class)->id();
    });

    app(BrandContext::class)->forget();

    seatingCancelRegistration($registrationId);

    expect($seen)->toBe([$brand->id, $brand->id])
        ->and(app(BrandContext::class)->current())->toBeNull();
});

/**
 * The two cases below build a booking directly with `ResourceBooking::factory()`
 * rather than through `seatingHold()`, which deliberately sets `holder_type`,
 * `holder_id` and `registration_id` to the same registration id and so can
 * never tell the Action's two lookup branches apart. Each case isolates one
 * branch of the `where('registration_id', ...)->orWhere(holder_type/holder_id)`
 * match so a regression in either survives none of the other tests above.
 */
it('releases a booking matched only by the registration_id column, whose holder is not the registration', function (): void {
    $brand = test()->withBrand();
    $eventId = (string) Str::ulid();
    $window = seatingProjectWindow($eventId, $brand->id);
    $type = seatingUnitType($eventId);
    $registrationId = (string) Str::ulid();

    $booking = ResourceBooking::factory()->create([
        'resource_id' => seatingPublishedResource($type, 'B-01')->id,
        'holder_type' => 'admission',
        'holder_id' => (string) Str::ulid(),
        'registration_id' => $registrationId,
        'starts_at' => $window->starts_at,
        'ends_at' => $window->ends_at,
        'state' => BookingState::Confirmed,
    ]);

    Event::fake([ResourceBookingReleased::class]);

    seatingCancelRegistration($registrationId);

    expect($booking->fresh()->state)->toBe(BookingState::Cancelled);

    Event::assertDispatchedTimes(ResourceBookingReleased::class, 1);
});

it('releases a booking matched only by the registration holder, with no registration_id column set', function (): void {
    $brand = test()->withBrand();
    $eventId = (string) Str::ulid();
    $window = seatingProjectWindow($eventId, $brand->id);
    $type = seatingUnitType($eventId);
    $registrationId = (string) Str::ulid();

    $booking = ResourceBooking::factory()->create([
        'resource_id' => seatingPublishedResource($type, 'B-02')->id,
        'holder_type' => ResourceBooking::HOLDER_REGISTRATION,
        'holder_id' => $registrationId,
        'registration_id' => null,
        'starts_at' => $window->starts_at,
        'ends_at' => $window->ends_at,
        'state' => BookingState::Confirmed,
    ]);

    Event::fake([ResourceBookingReleased::class]);

    seatingCancelRegistration($registrationId);

    expect($booking->fresh()->state)->toBe(BookingState::Cancelled);

    Event::assertDispatchedTimes(ResourceBookingReleased::class, 1);
});

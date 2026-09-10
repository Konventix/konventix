<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Resources\Actions\ConfirmResourceBooking;
use Konventix\Resources\Actions\ExpireResourceHolds;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Events\ResourceBookingReleased;
use Konventix\Resources\Models\ResourceType;

use function Pest\Laravel\travelTo;

/**
 * `resources:expire-holds` (design doc §9.7): a clocked hold past
 * `hold_expires_at` becomes `expired` and frees its slot. Confirmed
 * bookings have no clock, and registration-linked seat bookings are
 * confirmed in the same transaction as their hold, so the sweep never
 * touches either. The last run is recorded for the S6 heartbeat gauge.
 */
function seatingSweepFixture(): array
{
    $brand = test()->withBrand();
    $eventId = (string) Str::ulid();
    $window = seatingProjectWindow($eventId, $brand->id);
    $type = seatingUnitType($eventId);

    $lapsed = seatingHold(seatingPublishedResource($type, 'A-01'), $window);
    $confirmed = app(ConfirmResourceBooking::class)->handle(
        seatingHold(seatingPublishedResource($type, 'A-02'), $window, (string) Str::ulid()),
    );

    return [$brand, $window, $type, $lapsed, $confirmed];
}

it('expires a lapsed hold and leaves confirmed bookings and fresh holds alone', function (): void {
    [, $window, $type, $lapsed, $confirmed] = seatingSweepFixture();

    // The factory type holds for 30 minutes.
    travelTo(now()->addMinutes(31));

    $fresh = seatingHold(seatingPublishedResource($type, 'A-03'), $window);

    Event::fake([ResourceBookingReleased::class]);

    $expired = app(ExpireResourceHolds::class)->handle();

    expect($expired)->toBe(1)
        ->and($lapsed->fresh()->state)->toBe(BookingState::Expired)
        ->and($confirmed->fresh()->state)->toBe(BookingState::Confirmed)
        ->and($fresh->fresh()->state)->toBe(BookingState::Held);

    Event::assertDispatchedTimes(ResourceBookingReleased::class, 1);
    Event::assertDispatched(
        ResourceBookingReleased::class,
        fn (ResourceBookingReleased $e): bool => $e->bookingId === $lapsed->id && $e->reason === 'expired',
    );
});

it('frees the slot an expired hold occupied', function (): void {
    [, $window, , $lapsed] = seatingSweepFixture();

    travelTo(now()->addMinutes(31));
    app(ExpireResourceHolds::class)->handle();

    $again = seatingHold($lapsed->resourceUnscoped(), $window);

    expect($again->state)->toBe(BookingState::Held);
});

it('sets the brand context from each booking while releasing and restores it afterwards', function (): void {
    [$brand] = seatingSweepFixture();

    $seen = [];
    Event::listen(ResourceBookingReleased::class, function () use (&$seen): void {
        $seen[] = app(BrandContext::class)->id();
    });

    app(BrandContext::class)->forget();
    travelTo(now()->addMinutes(31));

    app(ExpireResourceHolds::class)->handle();

    expect($seen)->toBe([$brand->id])
        ->and(app(BrandContext::class)->current())->toBeNull();
});

it('records the last run for the heartbeat gauge, even when nothing expired', function (): void {
    $this->withBrand();

    expect(ExpireResourceHolds::lastRunAt())->toBeNull();

    travelTo('2027-06-15 10:00:00');

    app(ExpireResourceHolds::class)->handle();

    expect(Cache::get(ExpireResourceHolds::LAST_RUN_CACHE_KEY))->toBeString()
        ->and(ExpireResourceHolds::lastRunAt()?->format('Y-m-d H:i:s'))->toBe('2027-06-15 10:00:00');
});

it('is registered with artisan and scheduled every minute', function (): void {
    [, , , $lapsed] = seatingSweepFixture();

    travelTo(now()->addMinutes(31));

    $this->artisan('resources:expire-holds')
        ->expectsOutputToContain('Expired 1 lapsed hold(s).')
        ->assertSuccessful();

    expect($lapsed->fresh()->state)->toBe(BookingState::Expired);

    $entries = collect(app(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'resources:expire-holds'));

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->expression)->toBe('* * * * *');
});

it('never sweeps a held booking with no expiry clock', function (): void {
    // A type with hold_minutes 0 (HoldResourceBooking) holds without a
    // clock — the caller confirms in the same transaction (seat picks).
    // Built directly with the factory rather than seatingUnitType(),
    // which is canonical and has a single author.
    $brand = test()->withBrand();
    $eventId = (string) Str::ulid();
    $window = seatingProjectWindow($eventId, $brand->id);
    $type = ResourceType::factory()->forEvent($eventId)->create(['hold_minutes' => 0]);

    $unclocked = seatingHold(seatingPublishedResource($type, 'A-01'), $window);

    expect($unclocked->hold_expires_at)->toBeNull();

    travelTo(now()->addMinutes(31));

    $expired = app(ExpireResourceHolds::class)->handle();

    expect($expired)->toBe(0)
        ->and($unclocked->fresh()->state)->toBe(BookingState::Held);
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Resources\Actions\ReleaseOperatorReservation;
use Konventix\Resources\Actions\ReservePlaceForOperator;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Events\ResourceBookingConfirmed;
use Konventix\Resources\Events\ResourceBookingReleased;
use Konventix\Resources\Exceptions\ResourceUnavailableException;
use Konventix\Resources\Models\ResourceBooking;

/**
 * Orga reservations (design §7.3, Q7): hold plus confirm with holder
 * `operator`, released with reason `operator_released`. An occupied place
 * fails through the exclusion constraint(ADR-0020), never through a check
 * this code could race.
 */
function operatorReservationPlace(): array
{
    $brand = app(BrandContext::class)->currentOrFail();
    $eventId = seatingEvent($brand);
    $type = seatingCategory($eventId);
    $plan = seatingPlan($eventId);

    $a = seatingPlace($type->id, 'A-01');
    seatingSave($plan, seatingDocument([$a]));

    return [$plan, seatingPlaces($plan)->get($a['id']), $a];
}

it('reserves a place for the operator as a confirmed booking', function (): void {
    $this->withBrand();
    [, $place] = operatorReservationPlace();
    $userId = (string) Str::ulid();

    Event::fake([ResourceBookingConfirmed::class]);

    $booking = app(ReservePlaceForOperator::class)->handle($place, $userId);

    $type = $place->resourceType()->withoutGlobalScopes()->firstOrFail();
    $window = seatingWindow($type->event_id);

    expect($booking->state)->toBe(BookingState::Confirmed)
        ->and($booking->holder_type)->toBe(ResourceBooking::HOLDER_OPERATOR)
        ->and($booking->holder_id)->toBe($userId)
        ->and($booking->registration_id)->toBeNull()
        ->and($booking->resource_id)->toBe($place->id)
        ->and($booking->hold_expires_at)->toBeNull()
        ->and($booking->starts_at->equalTo($window->starts_at))->toBeTrue();

    Event::assertDispatched(ResourceBookingConfirmed::class);
});

it('fails through the exclusion constraint when the place is occupied', function (): void {
    $this->withBrand();
    [, $place] = operatorReservationPlace();
    $existing = seatingOccupy($place);

    // The Actions' nested DB::transaction is the savepoint the constraint
    // violation aborts (.ai/rules/tests.md), so the assertions below still
    // run inside RefreshDatabase's wrapper.
    expect(fn () => app(ReservePlaceForOperator::class)->handle($place, (string) Str::ulid()))
        ->toThrow(ResourceUnavailableException::class);

    expect($existing->fresh()->state)->toBe(BookingState::Confirmed)
        ->and(ResourceBooking::query()->where('resource_id', $place->id)->whereIn('state', BookingState::occupyingValues())->count())->toBe(1);
});

it('releases an operator reservation with reason operator_released and frees the place', function (): void {
    $this->withBrand();
    [, $place] = operatorReservationPlace();
    $userId = (string) Str::ulid();
    $booking = app(ReservePlaceForOperator::class)->handle($place, $userId);

    Event::fake([ResourceBookingReleased::class]);

    $released = app(ReleaseOperatorReservation::class)->handle($booking);

    expect($released->state)->toBe(BookingState::Released);
    Event::assertDispatched(ResourceBookingReleased::class, fn (ResourceBookingReleased $event): bool => $event->bookingId === $booking->id
        && $event->reason === 'operator_released');

    expect(app(ReservePlaceForOperator::class)->handle($place, $userId)->state)->toBe(BookingState::Confirmed);
});

it('refuses to release a booking that is not an operator reservation', function (): void {
    $this->withBrand();
    [, $place] = operatorReservationPlace();
    $booking = seatingOccupy($place);

    expect(fn () => app(ReleaseOperatorReservation::class)->handle($booking))
        ->toThrow(InvalidArgumentException::class);

    expect($booking->fresh()->state)->toBe(BookingState::Confirmed);
});

it('refuses to reserve a removed place', function (): void {
    $this->withBrand();
    [$plan, $place] = operatorReservationPlace();
    seatingSave($plan, seatingDocument([]));

    $removed = seatingPlaces($plan)->get($place->id);

    expect($removed->trashed())->toBeTrue();
    expect(fn () => app(ReservePlaceForOperator::class)->handle($removed, (string) Str::ulid()))
        ->toThrow(ValidationException::class);
});

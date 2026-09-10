<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Platform\Brands\BrandContext;
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

it('refuses more than one unit on a unit-mode resource in the Action', function (): void {
    // A seat is one thing. Quantity 2 on a unit resource used to write one
    // row with quantity 2 — one seat "booked twice" — and the claim path
    // then handed one seat to a registration that paid for two.
    $this->withBrand();

    $resource = bookingTestUnitResource();

    expect(fn () => bookingTestHold($resource, 1, quantity: 2))
        ->toThrow(InvalidArgumentException::class);

    expect(ResourceBooking::query()->count())->toBe(0);
});

it('names the event, the type, the registration and the label on every booking event', function (): void {
    $this->withBrand();

    Event::fake([ResourceBookingHeld::class, ResourceBookingConfirmed::class, ResourceBookingReleased::class]);

    $type = ResourceType::factory()->create();
    $resource = Resource::factory()->ofType($type)->create(['label' => 'A-07']);
    $registrationId = (string) Str::ulid();

    $booking = app(HoldResourceBooking::class)->handle(
        resource: $resource,
        holderType: ResourceBooking::HOLDER_REGISTRATION,
        holderId: $registrationId,
        startsAt: now()->startOfHour()->addHours(1),
        endsAt: now()->startOfHour()->addHours(3),
        registrationId: $registrationId,
    );

    // The label is a snapshot: a later plan edit renames the resource, the
    // history row keeps saying what it said when the booking happened.
    expect($booking->label)->toBe('A-07');

    Event::assertDispatched(
        ResourceBookingHeld::class,
        fn (ResourceBookingHeld $e): bool => $e->eventId === $type->event_id
            && $e->resourceTypeId === $type->id
            && $e->registrationId === $registrationId
            && $e->claimId === null
            && $e->label === 'A-07'
            && $e->ref === null
            && $e->holdExpiresAt !== null,
    );

    $confirmed = app(ConfirmResourceBooking::class)->handle($booking);

    Event::assertDispatched(
        ResourceBookingConfirmed::class,
        fn (ResourceBookingConfirmed $e): bool => $e->eventId === $type->event_id
            && $e->resourceTypeId === $type->id
            && $e->registrationId === $registrationId
            && $e->label === 'A-07',
    );

    app(ReleaseResourceBooking::class)->handle($confirmed, BookingState::Cancelled, 'registration_cancelled');

    Event::assertDispatched(
        ResourceBookingReleased::class,
        fn (ResourceBookingReleased $e): bool => $e->eventId === $type->event_id
            && $e->resourceTypeId === $type->id
            && $e->registrationId === $registrationId
            && $e->label === 'A-07'
            && $e->reason === 'registration_cancelled',
    );
});

it('records the claim, the amendment and an explicit label on the booking row', function (): void {
    $brand = $this->withBrand();

    $resource = bookingTestUnitResource();
    $claimId = (string) Str::ulid();
    $amendmentId = (string) Str::ulid();

    // resource_bookings.claim_id carries a foreign key onto resource_claims
    // as of Task 10; a real claim row is not a model concern yet (that
    // arrives with Konventix\Resources\Models\ResourceClaim in Task 12), so
    // this inserts the row directly.
    DB::table('resource_claims')->insert([
        'id' => $claimId,
        'brand_id' => $brand->id,
        'event_id' => (string) Str::ulid(),
        'registration_id' => (string) Str::ulid(),
        'resource_type_ids' => json_encode([$resource->resource_type_id]),
        'seating_mode' => 'assign',
    ]);

    $booking = app(HoldResourceBooking::class)->handle(
        resource: $resource,
        holderType: ResourceBooking::HOLDER_REGISTRATION,
        holderId: (string) Str::ulid(),
        startsAt: now()->startOfHour()->addHours(1),
        endsAt: now()->startOfHour()->addHours(3),
        claimId: $claimId,
        amendmentId: $amendmentId,
        label: 'Booth 12 (as sold)',
    );

    expect($booking->fresh()->claim_id)->toBe($claimId)
        ->and($booking->fresh()->amendment_id)->toBe($amendmentId)
        ->and($booking->fresh()->label)->toBe('Booth 12 (as sold)');
});

it('books two separate seats, in natural label order, for a resource claim of quantity two', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources');

    $eventId = (string) Str::ulid();
    seatingProjectWindow($eventId, $brand->id);
    $type = seatingUnitType($eventId);
    // Plain string order would put A-10 before A-2.
    foreach (['A-10', 'A-2', 'A-1'] as $label) {
        seatingPublishedResource($type, $label);
    }

    $registrationId = seatingConfirmRegistration($eventId, $brand->id, [seatingClaimGrant($type->id, 2)]);

    $bookings = ResourceBooking::query()->where('registration_id', $registrationId)->get();

    expect($bookings)->toHaveCount(2)
        ->and($bookings->pluck('quantity')->unique()->all())->toBe([1])
        ->and($bookings->pluck('resource_id')->unique())->toHaveCount(2)
        ->and($bookings->pluck('label')->sort()->values()->all())->toBe(['A-1', 'A-2']);
});

it('fulfils a confirmation that arrives with no brand in context', function (): void {
    // The Stripe webhook confirms paid registrations with no ambient brand;
    // the gate has to read the brand the payload names.
    $brand = $this->withBrand();
    $this->activateModule('resources');

    $eventId = (string) Str::ulid();
    seatingProjectWindow($eventId, $brand->id);
    $type = seatingUnitType($eventId);
    seatingPublishedResource($type, 'C-01');

    app(BrandContext::class)->forget();

    $registrationId = seatingConfirmRegistration($eventId, $brand->id, [seatingClaimGrant($type->id)]);

    expect(ResourceBooking::withoutBrandScope()->where('registration_id', $registrationId)->count())->toBe(1)
        ->and(app(BrandContext::class)->current())->toBeNull();
});

it('carries the place ref on the booking events', function (): void {
    $this->withBrand();

    $resource = bookingTestUnitResource();
    $resource->forceFill(['ref' => 'A-01'])->save();

    Event::fake([ResourceBookingHeld::class, ResourceBookingConfirmed::class]);

    $booking = app(HoldResourceBooking::class)->handle(
        resource: $resource,
        holderType: ResourceBooking::HOLDER_REGISTRATION,
        holderId: (string) Str::ulid(),
        startsAt: now()->startOfHour()->addHours(1),
        endsAt: now()->startOfHour()->addHours(3),
    );

    app(ConfirmResourceBooking::class)->handle($booking);

    Event::assertDispatched(ResourceBookingHeld::class, fn (ResourceBookingHeld $event): bool => $event->ref === 'A-01');
    Event::assertDispatched(ResourceBookingConfirmed::class, fn (ResourceBookingConfirmed $event): bool => $event->ref === 'A-01');
});

it('dispatches the release only once the outermost transaction commits', function (): void {
    // SavePlan and the seat assignment release inside their own
    // transaction (design §6.1 step 7). RefreshDatabase wraps every test in
    // one transaction, so "outermost" is level 1 here, not 0.
    $this->withBrand();

    $booking = bookingTestHold(bookingTestUnitResource(), 1);
    $levels = [];

    Event::listen(ResourceBookingReleased::class, function () use (&$levels): void {
        $levels[] = DB::transactionLevel();
    });

    DB::transaction(function () use ($booking, &$levels): void {
        app(ReleaseResourceBooking::class)->handle($booking);

        expect($levels)->toBe([]);
    });

    expect($levels)->toBe([1])
        ->and($booking->fresh()->state)->toBe(BookingState::Released);
});

it('dispatches hold and confirmation after the outermost commit as well', function (): void {
    $this->withBrand();

    $resource = bookingTestUnitResource();
    $seen = [];

    Event::listen(ResourceBookingHeld::class, function () use (&$seen): void {
        $seen[] = ['held', DB::transactionLevel()];
    });
    Event::listen(ResourceBookingConfirmed::class, function () use (&$seen): void {
        $seen[] = ['confirmed', DB::transactionLevel()];
    });

    DB::transaction(function () use ($resource, &$seen): void {
        app(ConfirmResourceBooking::class)->handle(bookingTestHold($resource, 1));

        expect($seen)->toBe([]);
    });

    expect($seen)->toBe([['held', 1], ['confirmed', 1]]);
});

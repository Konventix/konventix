<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Registration\Actions\JoinWaitlist;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Enums\WaitlistState;
use Konventix\Registration\Events\WaitlistOfferMade;
use Konventix\Registration\Models\Quota;
use Konventix\Registration\Models\RegistrationType;
use Konventix\Resources\Actions\PublishPlan;
use Konventix\Resources\Actions\ReleaseOperatorReservation;
use Konventix\Resources\Actions\ReservePlaceForOperator;
use Konventix\Resources\Actions\UnpublishPlan;
use Konventix\Resources\Events\PlaceSupplyChanged;
use Konventix\Resources\Models\Resource as BookableResource;

/**
 * Design §12b/#282: a seat category's ceiling is a Registration `Quota`,
 * and blocking a place or reserving one for the organisation consumes a
 * unit of it exactly as a sale would — `AdjustQuotaOnPlaceSupplyChanged`
 * (`app-modules/registration/src/Listeners/`) is the consumer, driven by
 * Resources' `PlaceSupplyChanged` (emitted from `SavePlan`'s projection,
 * `ReservePlaceForOperator` and `ReleaseOperatorReservation`).
 *
 * Helper names prefixed `placeSupply`: Pest helpers are process-global
 * (.ai/rules/tests.md). Used by this file only, so they stay here.
 */
function placeSupplySubmission(): SubmitRegistrationData
{
    return new SubmitRegistrationData(
        organizationName: null,
        billingRecipientName: 'Ada Lovelace',
        billingEmail: 'ada@example.test',
    );
}

it('consumes one quota unit when a place is blocked and releases it when unblocked', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    $category = seatingCategory($eventId);
    $quota = Quota::factory()->withCapacity(5)->create(['resource_type_id' => $category->id]);

    $plan = seatingPlan($eventId);
    $placeId = (string) Str::ulid();

    seatingSave($plan, seatingDocument([
        seatingPlace($category->id, 'A-01', ['id' => $placeId]),
    ]));

    expect($quota->refresh()->consumed)->toBe(0);

    seatingSave($plan, seatingDocument([
        seatingPlace($category->id, 'A-01', ['id' => $placeId, 'blocked' => true]),
    ]));

    expect($quota->refresh()->consumed)->toBe(1);

    seatingSave($plan, seatingDocument([
        seatingPlace($category->id, 'A-01', ['id' => $placeId]),
    ]));

    expect($quota->refresh()->consumed)->toBe(0);
});

it('treats an operator reservation like a block, and releasing it like an unblock', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEvent($brand);
    seatingWindow($eventId, $brand->id);
    $category = seatingCategory($eventId);
    $quota = Quota::factory()->withCapacity(5)->create(['resource_type_id' => $category->id]);

    $plan = seatingPlan($eventId);
    $placeId = (string) Str::ulid();

    seatingSave($plan, seatingDocument([
        seatingPlace($category->id, 'A-01', ['id' => $placeId]),
    ]));

    /** @var BookableResource $place */
    $place = BookableResource::withoutBrandScope()->findOrFail($placeId);

    $booking = app(ReservePlaceForOperator::class)->handle($place, (string) Str::ulid());

    expect($quota->refresh()->consumed)->toBe(1);

    app(ReleaseOperatorReservation::class)->handle($booking);

    expect($quota->refresh()->consumed)->toBe(0);
});

it('is idempotent when the supply event is replayed', function (): void {
    $brand = $this->withBrand();
    $categoryId = (string) Str::ulid();
    $quota = Quota::factory()->withCapacity(5)->create(['resource_type_id' => $categoryId]);

    $supplyChanged = new PlaceSupplyChanged(
        changeId: (string) Str::ulid(),
        brandId: $brand->id,
        eventId: (string) Str::ulid(),
        resourceTypeId: $categoryId,
        reason: 'blocked',
        direction: 'consumed',
        places: 1,
    );

    event($supplyChanged);
    event($supplyChanged);

    expect($quota->refresh()->consumed)->toBe(1);
});

it('does nothing when no quota is linked to the category', function (): void {
    $brand = $this->withBrand();

    // A quota exists for a *different* category — it must stay untouched.
    $quota = Quota::factory()->withCapacity(5)->create(['resource_type_id' => (string) Str::ulid()]);

    event(new PlaceSupplyChanged(
        changeId: (string) Str::ulid(),
        brandId: $brand->id,
        eventId: (string) Str::ulid(),
        resourceTypeId: (string) Str::ulid(),
        reason: 'blocked',
        direction: 'consumed',
        places: 1,
    ));

    expect($quota->refresh()->consumed)->toBe(0);
});

it('offers a freed unit to the waitlist when a released supply event drains the quota', function (): void {
    $brand = $this->withBrand();
    $categoryId = (string) Str::ulid();

    $quota = Quota::factory()->withCapacity(1)->create(['resource_type_id' => $categoryId]);
    $type = RegistrationType::factory()->create();
    $type->quotas()->attach($quota);

    // Fill the quota's only unit, then queue a waitlist entry behind it.
    app(SubmitRegistration::class)->handle($type, (string) Str::ulid(), placeSupplySubmission());
    $entry = app(JoinWaitlist::class)->handle($type, (string) Str::ulid(), placeSupplySubmission());

    Event::fake([WaitlistOfferMade::class]);

    event(new PlaceSupplyChanged(
        changeId: (string) Str::ulid(),
        brandId: $brand->id,
        eventId: (string) Str::ulid(),
        resourceTypeId: $categoryId,
        reason: 'unblocked',
        direction: 'released',
        places: 1,
    ));

    expect($entry->refresh()->state)->toBe(WaitlistState::Offered);

    Event::assertDispatched(WaitlistOfferMade::class, fn (WaitlistOfferMade $e): bool => $e->waitlistEntryId === $entry->id);
});

/**
 * #282 correction: `PublishPlan`/`UnpublishPlan` briefly emitted
 * `PlaceSupplyChanged` too, snapshotting the plan's current unblocked
 * count on every call. A plan drafted before sales open — the ordinary
 * order — then had its whole place count "released" on publish, and
 * `Quota::release()` floors at zero, so already-recorded sales vanished
 * from the ledger. Publishing and unpublishing must not move a quota at
 * all; see `UnpublishPlan`'s docblock and design §13.
 */
it('leaves a linked quota untouched when publishing a plan, even with sales already recorded', function (): void {
    $eventId = seatingEvent($this->withBrand());
    $category = seatingCategory($eventId);
    $quota = Quota::factory()->withCapacity(160)->create(['resource_type_id' => $category->id]);
    $type = RegistrationType::factory()->create();
    $type->quotas()->attach($quota);

    $plan = seatingPlan($eventId);

    $places = [];

    for ($i = 1; $i <= 160; $i++) {
        $places[] = seatingPlace($category->id, sprintf('A-%03d', $i));
    }

    seatingSave($plan, seatingDocument($places));

    for ($i = 0; $i < 3; $i++) {
        app(SubmitRegistration::class)->handle($type, (string) Str::ulid(), placeSupplySubmission());
    }

    expect($plan->published_at)->toBeNull()
        ->and($quota->refresh()->consumed)->toBe(3);

    Event::fake([PlaceSupplyChanged::class]);

    app(PublishPlan::class)->handle($plan);

    expect($plan->published_at)->not->toBeNull()
        ->and($quota->refresh()->consumed)->toBe(3);

    Event::assertNotDispatched(PlaceSupplyChanged::class);
});

it('leaves a linked quota untouched when unpublishing a plan, even with sales already recorded', function (): void {
    $eventId = seatingEvent($this->withBrand());
    $category = seatingCategory($eventId);
    $quota = Quota::factory()->withCapacity(160)->create(['resource_type_id' => $category->id]);
    $type = RegistrationType::factory()->create();
    $type->quotas()->attach($quota);

    $plan = seatingPlan($eventId);

    $places = [];

    for ($i = 1; $i <= 160; $i++) {
        $places[] = seatingPlace($category->id, sprintf('A-%03d', $i));
    }

    seatingSave($plan, seatingDocument($places));

    for ($i = 0; $i < 3; $i++) {
        app(SubmitRegistration::class)->handle($type, (string) Str::ulid(), placeSupplySubmission());
    }

    app(PublishPlan::class)->handle($plan);

    expect($quota->refresh()->consumed)->toBe(3);

    Event::fake([PlaceSupplyChanged::class]);

    app(UnpublishPlan::class)->handle($plan);

    expect($plan->published_at)->toBeNull()
        ->and($quota->refresh()->consumed)->toBe(3);

    Event::assertNotDispatched(PlaceSupplyChanged::class);
});

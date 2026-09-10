<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Konventix\Platform\Models\Brand;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Models\ResourceBooking;
use Konventix\Resources\Models\ResourceClaim;
use Konventix\Resources\Models\ResourceType;

/**
 * The concurrency half of the seat picker (seating design §11, optional):
 * Pest is single-threaded and cannot make two picks actually race, so
 * `resources:probe-seat-race` spawns real processes and this test runs it,
 * the way `InvoiceNumberingProbeTest` runs `commerce:probe-invoice-numbering`.
 */
beforeEach(function (): void {
    // Leave RefreshDatabase's transaction so the children, on their own PDO
    // handles, see the rows this test creates (.ai/rules/tests.md).
    DB::rollBack();
});

afterEach(function (): void {
    RefreshDatabaseState::$migrated = false;
});

it('proves that parallel picks of one place have exactly one winner', function (): void {
    // A fresh brand, not TestCase's cached $this->testBrand: that one was
    // created in setUp(), inside the transaction the beforeEach above just
    // rolled back, so $this->withBrand() alone would hand back a Brand
    // whose row no longer exists (the same trap InvoiceNumberingProbeTest's
    // plain Brand::factory()->create() sidesteps).
    $brand = $this->withBrand(Brand::factory()->create());
    $eventId = (string) Str::ulid();
    seatingWindow($eventId, $brand->id);
    $type = ResourceType::factory()->forEvent($eventId)->create();
    $place = seatingPlaceRow(seatingPlan($eventId, ['published_at' => now()]), $type, 'R-01');

    $exit = Artisan::call('resources:probe-seat-race', [
        'place' => $place->id,
        '--workers' => 3,
    ]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('exactly one winner');

    // Without --keep the probe cleans up: the winner's booking is released
    // and the probe claims are closed.
    expect(ResourceBooking::withoutBrandScope()->where('resource_id', $place->id)->where('state', BookingState::Confirmed->value)->count())->toBe(0)
        ->and(ResourceBooking::withoutBrandScope()->where('resource_id', $place->id)->where('state', BookingState::Released->value)->count())->toBe(1)
        ->and(ResourceClaim::withoutBrandScope()->where('event_id', $eventId)->where('state', ClaimState::Released->value)->count())->toBe(3);
});

it('fails loudly for a place that does not exist', function (): void {
    $exit = Artisan::call('resources:probe-seat-race', [
        'place' => '01JZZZZZZZZZZZZZZZZZZZZZZZ',
        '--workers' => 1,
    ]);

    expect($exit)->not->toBe(0)
        ->and(ResourceClaim::withoutBrandScope()->count())->toBe(0);
});

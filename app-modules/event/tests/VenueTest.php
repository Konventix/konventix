<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Event\Actions\AssignVenue;
use Konventix\Event\Data\OpeningPeriodData;
use Konventix\Event\Data\VenuePlaceData;
use Konventix\Event\Models\Event;
use Konventix\Event\Models\Venue;

/**
 * SPEC.md "Owns" — Venue: postal address, geo, access notes, and opening
 * times as a typed structure rather than an untyped JSON blob (ADR-0009).
 */
beforeEach(function (): void {
    $this->withBrand();
});

it('round-trips opening times as typed OpeningPeriodData through the cast', function (): void {
    $venue = Venue::factory()->create([
        'opening_times' => [
            new OpeningPeriodData(dayOfWeek: 1, opensAt: '09:00', closesAt: '18:00'),
            new OpeningPeriodData(dayOfWeek: 2, opensAt: '09:00', closesAt: '18:00'),
        ],
    ]);

    $fresh = $venue->fresh();

    expect($fresh->opening_times)->toHaveCount(2)
        ->and($fresh->opening_times->first())->toBeInstanceOf(OpeningPeriodData::class)
        ->and($fresh->opening_times->first()->dayOfWeek)->toBe(1)
        ->and($fresh->opening_times->first()->opensAt)->toBe('09:00')
        ->and($fresh->opening_times->last()->dayOfWeek)->toBe(2);
});

it('stores an empty opening times collection by default', function (): void {
    $venue = Venue::factory()->create(['opening_times' => []]);

    expect($venue->fresh()->opening_times)->toHaveCount(0);
});

it('stamps brand_id and keeps address and geo fields', function (): void {
    $brand = $this->withBrand();

    $venue = Venue::factory()->create([
        'name' => 'Congress Centrum',
        'city' => 'Hamburg',
        'country_code' => 'DE',
    ]);

    expect($venue->brand_id)->toBe($brand->getKey())
        ->and($venue->city)->toBe('Hamburg')
        ->and($venue->country_code)->toBe('DE');
});

// Task 18: a venue carries the details its public page renders — parking
// spots and loading zones behind one typed cast, an ordered list of media
// ULIDs with one primary, and a per-brand slug.
it('round-trips parking spots and loading zones as typed VenuePlaceData', function (): void {
    $venue = Venue::factory()->create([
        'parking_spots' => [
            new VenuePlaceData(label: 'Lot A', address: 'Hafenstr. 1', latitude: 53.5, longitude: 10.0),
            ['label' => 'Lot B'],
        ],
        'loading_zones' => [
            new VenuePlaceData(label: 'Ramp North'),
        ],
    ]);

    $fresh = $venue->fresh();

    expect($fresh->parking_spots)->toHaveCount(2)
        ->and($fresh->parking_spots->first())->toBeInstanceOf(VenuePlaceData::class)
        ->and($fresh->parking_spots->first()->label)->toBe('Lot A')
        ->and($fresh->parking_spots->first()->latitude)->toBe(53.5)
        ->and($fresh->parking_spots->last()->label)->toBe('Lot B')
        ->and($fresh->parking_spots->last()->address)->toBeNull()
        ->and($fresh->loading_zones)->toHaveCount(1)
        ->and($fresh->loading_zones->first()->label)->toBe('Ramp North');
});

it('drops blanks and duplicates from the image list on write', function (): void {
    $first = (string) Str::ulid();
    $second = (string) Str::ulid();

    $venue = Venue::factory()->create([
        'image_media_ids' => [$first, '', $second, $first],
    ]);

    expect($venue->fresh()->image_media_ids)->toBe([$first, $second]);
});

it('shows the primary picture first and keeps the operator order behind it', function (): void {
    [$first, $second, $third] = [(string) Str::ulid(), (string) Str::ulid(), (string) Str::ulid()];

    $venue = Venue::factory()->create([
        'image_media_ids' => [$first, $second, $third],
        'primary_media_id' => $second,
    ]);

    expect($venue->orderedImageMediaIds())->toBe([$second, $first, $third]);
});

it('falls back to the first picture when the primary is not one of them', function (): void {
    [$kept, $gone] = [(string) Str::ulid(), (string) Str::ulid()];

    $venue = Venue::factory()->create([
        'image_media_ids' => [$kept],
        'primary_media_id' => $gone,
    ]);

    expect($venue->fresh()->primary_media_id)->toBe($kept);

    $venue->update(['image_media_ids' => []]);

    expect($venue->fresh()->primary_media_id)->toBeNull();
});

it('is publicly reachable only through an event that is itself visible', function (): void {
    $venue = Venue::factory()->create();

    expect($venue->isPubliclyReachable())->toBeFalse();

    $draft = Event::factory()->create();
    app(AssignVenue::class)->handle($draft, $venue, isPrimary: true);

    expect($venue->isPubliclyReachable())->toBeFalse();

    $published = Event::factory()->published()->create();
    app(AssignVenue::class)->handle($published, $venue);

    expect($venue->isPubliclyReachable())->toBeTrue();
});

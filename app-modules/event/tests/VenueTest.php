<?php

declare(strict_types=1);

use Konventix\Event\Data\OpeningPeriodData;
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

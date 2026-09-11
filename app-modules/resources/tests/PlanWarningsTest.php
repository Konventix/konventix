<?php

declare(strict_types=1);

use Konventix\Resources\Enums\PlanWarningCode;
use Konventix\Resources\Plans\PlanWarnings;

/**
 * Warnings never block a save (design §6.1 step 5); they tell the editor
 * about places that overlap inside a zone and places that lie outside every
 * zone. A plan without zones gets no "outside" warnings — every place would
 * be one.
 */
it('warns about overlapping places within a zone', function (): void {
    $zone = seatingZone(['x' => 0, 'y' => 0]);
    $a = seatingPlace('type', 'A-01', ['parentId' => $zone['id'], 'x' => 1000, 'y' => 1000]);
    $b = seatingPlace('type', 'A-02', ['parentId' => $zone['id'], 'x' => 1400, 'y' => 1000]);

    $warnings = PlanWarnings::collect(seatingDocument([$zone, $a, $b]));

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]->code)->toBe(PlanWarningCode::Overlap)
        ->and($warnings[0]->placeIds)->toBe([$a['id'], $b['id']])
        ->and($warnings[0]->message)->toBe('Places A-01 and A-02 overlap.');
});

it('warns about a place outside every zone', function (): void {
    $zone = seatingZone(['x' => 0, 'y' => 0]);
    $inside = seatingPlace('type', 'A-01', ['parentId' => $zone['id'], 'x' => 1000, 'y' => 1000]);
    $outside = seatingPlace('type', 'X-01', ['x' => 25000, 'y' => 0]);

    $warnings = PlanWarnings::collect(seatingDocument([$zone, $inside, $outside]));

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]->code)->toBe(PlanWarningCode::OutsideZone)
        ->and($warnings[0]->placeIds)->toBe([$outside['id']])
        ->and($warnings[0]->message)->toBe('Place X-01 lies outside every zone.');
});

it('stays silent for touching places and for a plan without zones', function (): void {
    $a = seatingPlace('type', 'A-01', ['x' => 0]);
    $b = seatingPlace('type', 'A-02', ['x' => 800]);

    expect(PlanWarnings::collect(seatingDocument([$a, $b])))->toBe([]);
});

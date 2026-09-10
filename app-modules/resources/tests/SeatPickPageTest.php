<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Resources\Actions\ComputePlanAvailability;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Enums\PlaceState;
use Konventix\Resources\Models\Plan;
// Aliased: an unqualified "Resource" in a docblock generic collides with
// PHP's `resource` pseudo-type and pint's docblock fixer silently lowercases
// it (tests/Helpers.php's own header comment explains the same trap).
use Konventix\Resources\Models\Resource as BookableResource;
use Konventix\Resources\Models\ResourceClaim;
use Konventix\Resources\Models\ResourceEventWindow;
use Konventix\Resources\Models\ResourceType;

/**
 * The seat picker (design §7.4): a page only the claim's registrant can
 * open, an availability map that is one query over occupying bookings, and
 * a post that goes through `AssignPlacesToRegistration` so the exclusion
 * constraint, not the page, decides a race.
 *
 * `seatPickScene()` is file-local because only this file builds a whole
 * scene; the row builders it calls live in tests/Helpers.php because the
 * slot test in Registration needs them too.
 */

/**
 * @return array{user: User, registrationId: string, eventId: string, type: ResourceType, claim: ResourceClaim, plan: Plan, places: list<BookableResource>, window: ResourceEventWindow}
 */
function seatPickScene(Brand $brand, int $places = 3, int $quantity = 1): array
{
    $eventId = (string) Str::ulid();
    $window = seatingWindow($eventId, $brand->getKey());
    $type = seatingCategory($eventId);
    ['plan' => $plan, 'places' => $rows] = seatingPublishedPlan($eventId, $type, $places);
    $user = User::factory()->create();
    $registrationId = (string) Str::ulid();
    $claim = seatingClaim($eventId, $registrationId, (string) $user->getKey(), [$type->getKey()], $quantity);

    return [
        'user' => $user,
        'registrationId' => $registrationId,
        'eventId' => $eventId,
        'type' => $type,
        'claim' => $claim,
        'plan' => $plan,
        'places' => $rows,
        'window' => $window,
    ];
}

it('marks every published, unblocked place free when nothing is booked', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);
    $scene = seatPickScene($brand, places: 2);

    $availability = app(ComputePlanAvailability::class)->handle($scene['claim']);

    expect($availability->eventId)->toBe($scene['eventId'])
        ->and($availability->places)->toHaveCount(2)
        ->and($availability->places[$scene['places'][0]->getKey()])->toBe(PlaceState::Free)
        ->and($availability->places[$scene['places'][1]->getKey()])->toBe(PlaceState::Free);
});

it('tells the registrant\'s own booking apart from other people\'s holds and bookings', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);
    $scene = seatPickScene($brand, places: 4);
    [$mine, $held, $booked, $free] = $scene['places'];

    seatingBooking($mine, $scene['window'], $scene['registrationId'], $scene['claim']);
    seatingBooking($held, $scene['window'], (string) Str::ulid(), state: BookingState::Held);
    seatingBooking($booked, $scene['window'], (string) Str::ulid());

    $places = app(ComputePlanAvailability::class)->handle($scene['claim'])->places;

    expect($places[$mine->getKey()])->toBe(PlaceState::Mine)
        ->and($places[$held->getKey()])->toBe(PlaceState::Held)
        ->and($places[$booked->getKey()])->toBe(PlaceState::Booked)
        ->and($places[$free->getKey()])->toBe(PlaceState::Free);
});

it('reports blocked places and places on unpublished plans as unavailable', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);
    $scene = seatPickScene($brand, places: 2);
    $scene['places'][1]->forceFill(['blocked' => true])->save();
    ['places' => $draftPlaces] = seatingPublishedPlan($scene['eventId'], $scene['type'], 1, published: false);

    $places = app(ComputePlanAvailability::class)->handle($scene['claim'])->places;

    expect($places[$scene['places'][0]->getKey()])->toBe(PlaceState::Free)
        ->and($places[$scene['places'][1]->getKey()])->toBe(PlaceState::Unavailable)
        ->and($places[$draftPlaces[0]->getKey()])->toBe(PlaceState::Unavailable);
});

it('ignores released bookings and places of other events', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);
    $scene = seatPickScene($brand, places: 1);
    seatingBooking($scene['places'][0], $scene['window'], (string) Str::ulid(), state: BookingState::Released);

    $otherEvent = (string) Str::ulid();
    ['places' => $foreign] = seatingPublishedPlan($otherEvent, seatingCategory($otherEvent), 1);

    $places = app(ComputePlanAvailability::class)->handle($scene['claim'])->places;

    expect($places[$scene['places'][0]->getKey()])->toBe(PlaceState::Free)
        ->and($places)->not->toHaveKey($foreign[0]->getKey());
});

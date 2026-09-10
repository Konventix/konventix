<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Resources\Actions\ComputePlanAvailability;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Enums\PlaceState;
use Konventix\Resources\Models\Plan;
// Aliased: an unqualified "Resource" in a docblock generic collides with
// PHP's `resource` pseudo-type and pint's docblock fixer silently lowercases
// it (tests/Helpers.php's own header comment explains the same trap).
use Konventix\Resources\Models\Resource as BookableResource;
use Konventix\Resources\Models\ResourceBooking;
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

it('renders the picker with SeatPickerData for the claim owner', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);
    $scene = seatPickScene($brand, places: 2, quantity: 2);
    seatingBooking($scene['places'][0], $scene['window'], $scene['registrationId'], $scene['claim']);

    $this->actingAs($scene['user'])
        ->get('/my/registrations/'.$scene['registrationId'].'/seat')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('seating/Pick')
            ->where('registrationId', $scene['registrationId'])
            ->where('claim.id', $scene['claim']->getKey())
            ->where('claim.quantity', 2)
            ->where('claim.resourceTypeIds', [$scene['type']->getKey()])
            ->where('categories.0.id', $scene['type']->getKey())
            ->where('categories.0.token', 'chart-1')
            ->where('categories.0.symbol', 'armchair')
            ->has('plans', 1)
            ->where('plans.0.id', $scene['plan']->getKey())
            ->where('plans.0.widthMm', 10000)
            ->has('plans.0.document.elements', 4)
            ->where('availability.places.'.$scene['places'][0]->getKey(), 'mine')
            ->where('availability.places.'.$scene['places'][1]->getKey(), 'free')
            ->where('currentPlaceIds', [$scene['places'][0]->getKey()])
            ->where('noFreePlaces', false));
});

it('leaves unpublished plans out of the page', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);
    $scene = seatPickScene($brand, places: 1);
    seatingPublishedPlan($scene['eventId'], $scene['type'], 1, published: false);

    $this->actingAs($scene['user'])
        ->get('/my/registrations/'.$scene['registrationId'].'/seat')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('plans', 1));
});

it('answers 404 to another account', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);
    $scene = seatPickScene($brand);

    $this->actingAs(User::factory()->create())
        ->get('/my/registrations/'.$scene['registrationId'].'/seat')
        ->assertNotFound();
});

it('answers 404 for a registration without a claim and for a released one', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/my/registrations/'.Str::ulid().'/seat')
        ->assertNotFound();

    $scene = seatPickScene($brand);
    $scene['claim']->forceFill(['state' => ClaimState::Released])->save();

    $this->actingAs($scene['user'])
        ->get('/my/registrations/'.$scene['registrationId'].'/seat')
        ->assertNotFound();
});

it('sends a signed-out visitor to log in', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);

    $this->get('/my/registrations/'.Str::ulid().'/seat')->assertRedirect('/login');
});

it('has no route at all while the module is off for the brand', function (): void {
    $brand = $this->withBrand();
    $scene = seatPickScene($brand);

    $this->actingAs($scene['user'])
        ->get('/my/registrations/'.$scene['registrationId'].'/seat')
        ->assertNotFound();

    $this->actingAs($scene['user'])
        ->post('/my/registrations/'.$scene['registrationId'].'/seat', ['placeIds' => [$scene['places'][0]->getKey()]])
        ->assertNotFound();

    expect(ResourceBooking::query()->count())->toBe(0);
});

it('serves availability as JSON to the owner and scopes it to the brand', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);
    $scene = seatPickScene($brand, places: 2);
    seatingBooking($scene['places'][1], $scene['window'], (string) Str::ulid(), state: BookingState::Held);

    $this->actingAs($scene['user'])
        ->getJson('/my/registrations/'.$scene['registrationId'].'/seat/availability')
        ->assertOk()
        ->assertJsonPath('eventId', $scene['eventId'])
        ->assertJsonPath('places.'.$scene['places'][0]->getKey(), 'free')
        ->assertJsonPath('places.'.$scene['places'][1]->getKey(), 'held');

    $other = Brand::factory()->withDomain('other-brand.test')->create();
    $this->activateModule('resources', $other);

    $this->actingAs($scene['user'])
        ->getJson('http://other-brand.test/my/registrations/'.$scene['registrationId'].'/seat/availability')
        ->assertNotFound();
});

it('saves the pick and returns to the registration page', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);
    $scene = seatPickScene($brand, places: 2);

    $this->actingAs($scene['user'])
        ->from('/my/registrations/'.$scene['registrationId'].'/seat')
        ->post('/my/registrations/'.$scene['registrationId'].'/seat', ['placeIds' => [$scene['places'][1]->getKey()]])
        ->assertRedirect('/my/registrations/'.$scene['registrationId'])
        ->assertSessionHasNoErrors();

    $booking = ResourceBooking::query()->where('resource_id', $scene['places'][1]->getKey())->sole();

    expect($booking->state)->toBe(BookingState::Confirmed)
        ->and($booking->registration_id)->toBe($scene['registrationId'])
        ->and($booking->claim_id)->toBe($scene['claim']->getKey())
        ->and($scene['claim']->fresh()->state)->toBe(ClaimState::Placed);
});

it('reports a place taken meanwhile as a placeIds error and books nothing', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);
    $scene = seatPickScene($brand, places: 1);
    seatingBooking($scene['places'][0], $scene['window'], (string) Str::ulid());

    $this->actingAs($scene['user'])
        ->from('/my/registrations/'.$scene['registrationId'].'/seat')
        ->post('/my/registrations/'.$scene['registrationId'].'/seat', ['placeIds' => [$scene['places'][0]->getKey()]])
        ->assertRedirect('/my/registrations/'.$scene['registrationId'].'/seat')
        ->assertSessionHasErrors('placeIds');

    // The refusal happened inside the Action's own transaction (a
    // savepoint under RefreshDatabase), so the connection is still usable.
    expect(ResourceBooking::query()->where('registration_id', $scene['registrationId'])->count())->toBe(0)
        ->and($scene['claim']->fresh()->state)->toBe(ClaimState::Unplaced);
});

it('refuses an empty pick before touching the Action', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);
    $scene = seatPickScene($brand);

    $this->actingAs($scene['user'])
        ->from('/my/registrations/'.$scene['registrationId'].'/seat')
        ->post('/my/registrations/'.$scene['registrationId'].'/seat', ['placeIds' => []])
        ->assertSessionHasErrors('placeIds');
});

/**
 * The batch's own addition (2026-09-10, ahead of design §12b/#282's quota
 * mechanism): a category with nothing free is its own explicit page state,
 * never a bare validation error the person only meets after trying to pick.
 * `HasFreePlaceForClaim` is the one read `SeatPickController` and
 * `ResourcesServiceProvider`'s `registration.mine.show` slot both call, so
 * this and `RegistrationMineSeatSlotTest`'s matching test can never drift
 * apart on what "sold out" means for the same claim.
 */
it('flags the page when nothing is free in the claim\'s categories', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);
    $scene = seatPickScene($brand, places: 1);
    seatingBooking($scene['places'][0], $scene['window'], (string) Str::ulid());

    $this->actingAs($scene['user'])
        ->get('/my/registrations/'.$scene['registrationId'].'/seat')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('noFreePlaces', true));
});

it('does not flag the page while a place in the claim\'s categories is still free', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);
    $scene = seatPickScene($brand, places: 2);
    seatingBooking($scene['places'][0], $scene['window'], (string) Str::ulid());

    $this->actingAs($scene['user'])
        ->get('/my/registrations/'.$scene['registrationId'].'/seat')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('noFreePlaces', false));
});

<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Resources\Actions\ExportPlan;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Models\Plan;
use Konventix\Resources\Models\Resource;
use Konventix\Resources\Models\ResourceBooking;
use Konventix\Resources\Models\ResourceType;

/**
 * Design §8.2 / §11 (`PlanAdminTest`, part 1): the plan console on the
 * operator host — index, create, publish, export, import, occupancy and
 * operator reservations, plus the capability gate. Edit, update and
 * validate are S7's half of this file.
 *
 * Every brand-owned row is created before the admin-host request: that
 * request resolves no brand, and a factory row created after it has
 * nothing to be stamped with (.ai/rules/tests.md).
 *
 * Places come from `seatingPlaceRow($plan, $type, $ref, $attributes)` in
 * `app-modules/resources/tests/Helpers.php` (Task 25). It is not
 * redeclared here: helper names are process-global, so a second
 * declaration would be a fatal `Cannot redeclare` for every worker
 * (.ai/rules/tests.md).
 */
it('403s the plan console without the manage-plans capability', function (): void {
    [, $admin] = seatingOperator(['platform.access-admin', 'resources.manage-resource-types']);

    $this->actingAs($admin)
        ->get('http://admin.seating.test/admin/plans')
        ->assertForbidden();
});

it('lists plans with event name, place count, version and publication', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEventWindow($brand, 'SXLan 2027');
    [, $admin] = seatingOperator();

    $type = ResourceType::factory()->forEvent($eventId)->create();
    $plan = Plan::factory()->create(['event_id' => $eventId, 'name' => 'Hall A', 'version' => 3]);
    seatingPlaceRow($plan, $type, 'A-01');
    seatingPlaceRow($plan, $type, 'A-02');
    Plan::factory()->create(['event_id' => $eventId, 'name' => 'Hall B']);

    $this->actingAs($admin)
        ->get('http://admin.seating.test/admin/plans')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/plans/Index')
            ->has('plans.data', 2)
            ->where('plans.data.0.name', 'Hall A')
            ->where('plans.data.0.eventName', 'SXLan 2027')
            ->where('plans.data.0.places', 2)
            ->where('plans.data.0.version', 3)
            ->where('plans.data.0.publishedAt', null)
            ->where('plans.data.1.places', 0)
            ->has('events', 1));
});

it('creates an empty plan at version 0 under the chosen brand', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEventWindow($brand);
    [, $admin] = seatingOperator();

    $this->actingAs($admin)
        ->post('http://admin.seating.test/admin/plans', [
            'brand_id' => $brand->getKey(),
            'eventId' => $eventId,
            'name' => 'Hall A',
            'floorLabel' => 'Ground floor',
            'widthMm' => 40000,
            'heightMm' => 25000,
            'unitDisplay' => 'cm',
            'snapStep' => 100,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $plan = Plan::withoutBrandScope()->where('name', 'Hall A')->sole();

    expect($plan->brand_id)->toBe($brand->getKey())
        ->and($plan->event_id)->toBe($eventId)
        ->and($plan->version)->toBe(0)
        ->and($plan->published_at)->toBeNull()
        ->and($plan->width_mm)->toBe(40000)
        // The brief's own draft read `elements` as a Collection
        // (`->elements->count()`); `PlanDocumentData::$elements` is a plain
        // `list<PlanElementData>` array (see PlanGeometry's `foreach
        // ($document->elements as $element)`), so `->count()` on it is a
        // fatal `Call to a member function count() on array` — count() the
        // array itself instead.
        ->and(count($plan->document->elements))->toBe(0)
        ->and($plan->created_by)->toBe($admin->getKey());
});

it('refuses a second plan with the same name for the event', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEventWindow($brand);
    [, $admin] = seatingOperator();

    Plan::factory()->create(['event_id' => $eventId, 'name' => 'Hall A']);

    $this->actingAs($admin)
        ->post('http://admin.seating.test/admin/plans', [
            'brand_id' => $brand->getKey(),
            'eventId' => $eventId,
            'name' => 'Hall A',
            'floorLabel' => null,
            'widthMm' => 40000,
            'heightMm' => 25000,
            'unitDisplay' => 'cm',
            'snapStep' => 100,
        ])
        ->assertSessionHasErrors('name');

    expect(Plan::withoutBrandScope()->where('name', 'Hall A')->count())->toBe(1);
});

it('publishes and unpublishes a plan, flipping is_published on its places', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEventWindow($brand);
    [, $admin] = seatingOperator();

    $type = ResourceType::factory()->forEvent($eventId)->create();
    $plan = Plan::factory()->create(['event_id' => $eventId]);
    seatingPlaceRow($plan, $type, 'A-01');

    $this->actingAs($admin)
        ->post('http://admin.seating.test/admin/plans/'.$plan->getKey().'/publish')
        ->assertRedirect();

    expect($plan->refresh()->published_at)->not->toBeNull()
        ->and(Resource::withoutBrandScope()->where('plan_id', $plan->getKey())->where('is_published', true)->count())->toBe(1);

    $this->actingAs($admin)
        ->post('http://admin.seating.test/admin/plans/'.$plan->getKey().'/unpublish')
        ->assertRedirect();

    expect($plan->refresh()->published_at)->toBeNull()
        ->and(Resource::withoutBrandScope()->where('plan_id', $plan->getKey())->where('is_published', true)->count())->toBe(0);
});

it('exports the canonical document as a JSON download', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEventWindow($brand);
    [, $admin] = seatingOperator();

    $plan = Plan::factory()->create(['event_id' => $eventId, 'name' => 'Hall A']);
    $expected = app(ExportPlan::class)->handle($plan);

    $response = $this->actingAs($admin)
        ->get('http://admin.seating.test/admin/plans/'.$plan->getKey().'/export')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertDownload('hall-a.json');

    expect($response->streamedContent())->toBe($expected);
});

it('imports an uploaded document into a new plan', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEventWindow($brand);
    [, $admin] = seatingOperator();

    $type = ResourceType::factory()->forEvent($eventId)->create();
    $document = seatingPlanDocument($type->getKey(), places: 3);

    $this->actingAs($admin)
        ->post('http://admin.seating.test/admin/plans/upload', [
            'brand_id' => $brand->getKey(),
            'eventId' => $eventId,
            'name' => 'Imported hall',
            'file' => UploadedFile::fake()->createWithContent('hall.json', (string) json_encode($document)),
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $plan = Plan::withoutBrandScope()->where('name', 'Imported hall')->sole();

    expect($plan->brand_id)->toBe($brand->getKey())
        ->and($plan->version)->toBe(1)
        ->and(Resource::withoutBrandScope()->where('plan_id', $plan->getKey())->count())->toBe(3)
        ->and(Resource::withoutBrandScope()->where('plan_id', $plan->getKey())->pluck('ref')->sort()->values()->all())
        ->toBe(['A-01', 'A-02', 'A-03']);
});

it('refuses an upload that is not a JSON document', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEventWindow($brand);
    [, $admin] = seatingOperator();

    $this->actingAs($admin)
        ->post('http://admin.seating.test/admin/plans/upload', [
            'brand_id' => $brand->getKey(),
            'eventId' => $eventId,
            'name' => 'Broken',
            'file' => UploadedFile::fake()->createWithContent('hall.json', '{not json'),
        ])
        ->assertSessionHasErrors('file');

    expect(Plan::withoutBrandScope()->where('name', 'Broken')->exists())->toBeFalse();
});

it('answers occupancy per place from the bookings', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEventWindow($brand);
    [, $admin] = seatingOperator();

    $type = ResourceType::factory()->forEvent($eventId)->create();
    $plan = Plan::factory()->create(['event_id' => $eventId]);
    $booked = seatingPlaceRow($plan, $type, 'A-01');
    $held = seatingPlaceRow($plan, $type, 'A-02');
    $blocked = seatingPlaceRow($plan, $type, 'A-03', ['blocked' => true]);
    $free = seatingPlaceRow($plan, $type, 'A-04');

    $registrationId = (string) Str::ulid();
    $confirmed = ResourceBooking::factory()->create([
        'resource_id' => $booked->getKey(),
        'registration_id' => $registrationId,
        'holder_id' => $registrationId,
        'state' => BookingState::Confirmed,
    ]);
    ResourceBooking::factory()->create([
        'resource_id' => $held->getKey(),
        'state' => BookingState::Held,
        'hold_expires_at' => now()->addMinutes(30),
    ]);
    // History does not occupy: a released booking must read as free.
    ResourceBooking::factory()->create([
        'resource_id' => $free->getKey(),
        'state' => BookingState::Released,
    ]);

    $this->actingAs($admin)
        ->getJson('http://admin.seating.test/admin/plans/'.$plan->getKey().'/occupancy')
        ->assertOk()
        ->assertJsonPath('places.'.$booked->getKey().'.state', 'booked')
        ->assertJsonPath('places.'.$booked->getKey().'.holderType', 'registration')
        ->assertJsonPath('places.'.$booked->getKey().'.registrationId', $registrationId)
        ->assertJsonPath('places.'.$booked->getKey().'.bookingId', $confirmed->getKey())
        ->assertJsonPath('places.'.$held->getKey().'.state', 'held')
        ->assertJsonPath('places.'.$blocked->getKey().'.state', 'unavailable')
        ->assertJsonPath('places.'.$free->getKey().'.state', 'free')
        ->assertJsonPath('places.'.$free->getKey().'.bookingId', null);
});

it('reserves a place for the operator and refuses the same place twice', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEventWindow($brand);
    [, $admin] = seatingOperator();

    $type = ResourceType::factory()->forEvent($eventId)->create();
    $plan = Plan::factory()->create(['event_id' => $eventId]);
    $place = seatingPlaceRow($plan, $type, 'A-01');

    $url = 'http://admin.seating.test/admin/plans/'.$plan->getKey().'/places/'.$place->getKey().'/reserve';

    $this->actingAs($admin)->post($url)->assertSessionHasNoErrors()->assertRedirect();

    $booking = ResourceBooking::withoutBrandScope()->where('resource_id', $place->getKey())->sole();

    expect($booking->holder_type)->toBe(ResourceBooking::HOLDER_OPERATOR)
        ->and($booking->holder_id)->toBe($admin->getKey())
        ->and($booking->state)->toBe(BookingState::Confirmed);

    // The second hold dies on the exclusion constraint inside the Action's
    // own DB::transaction (a savepoint under RefreshDatabase), so the
    // connection is still usable for the assertion after the 422
    // (.ai/rules/tests.md, design §10).
    $this->actingAs($admin)->post($url)->assertSessionHasErrors('place');

    expect(ResourceBooking::withoutBrandScope()->where('resource_id', $place->getKey())->count())->toBe(1);
});

it('releases an operator reservation', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEventWindow($brand);
    [, $admin] = seatingOperator();

    $type = ResourceType::factory()->forEvent($eventId)->create();
    $plan = Plan::factory()->create(['event_id' => $eventId]);
    $place = seatingPlaceRow($plan, $type, 'A-01');

    $url = 'http://admin.seating.test/admin/plans/'.$plan->getKey().'/places/'.$place->getKey().'/reserve';

    $this->actingAs($admin)->post($url)->assertRedirect();
    $this->actingAs($admin)->delete($url)->assertSessionHasNoErrors()->assertRedirect();

    expect(ResourceBooking::withoutBrandScope()->where('resource_id', $place->getKey())->sole()->state)
        ->toBe(BookingState::Released);

    // Nothing left to release: a field error, not a 500.
    $this->actingAs($admin)->delete($url)->assertSessionHasErrors('place');
});

it('needs book-on-behalf for reservations, not manage-plans', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEventWindow($brand);
    [, $admin] = seatingOperator(['platform.access-admin', 'resources.manage-plans']);

    $type = ResourceType::factory()->forEvent($eventId)->create();
    $plan = Plan::factory()->create(['event_id' => $eventId]);
    $place = seatingPlaceRow($plan, $type, 'A-01');

    $this->actingAs($admin)
        ->post('http://admin.seating.test/admin/plans/'.$plan->getKey().'/places/'.$place->getKey().'/reserve')
        ->assertForbidden();
});

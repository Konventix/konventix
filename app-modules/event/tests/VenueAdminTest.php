<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Event\Data\OpeningPeriodData;
use Konventix\Event\Models\Venue;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Models\MediaUsage;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ADR-0029: the venue console lives on the operator's admin host, behind
 * `event.view-venues` for the list and `event.manage-venues` for the edit
 * page. The edit page is where a venue gets everything its public page
 * renders — description, both coordinate pairs, arrival, parking, loading
 * zones, seat plan, and an ordered set of pictures from the brand's media
 * library (ADR-0035) with one primary.
 *
 * Every brand-owned row is created *before* the admin-host request that
 * reads it: an admin-host request resolves no brand and BrandScope hardens
 * brand-owned queries to nothing (ADR-0029, .ai/rules/tests.md).
 */
function venuesAdminOperator(array $permissions = ['platform.access-admin', 'event.manage-venues']): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.venuesadmin.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => 'Operator Admin',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($permissions);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin];
}

/**
 * @return array{0: User, 1: Venue, 2: array<string, mixed>}
 */
function venuesAdminEditable(): array
{
    [, $admin] = venuesAdminOperator();

    test()->withBrand();
    $venue = Venue::factory()->create(['name' => 'Congress Centrum', 'slug' => 'congress-centrum']);

    return [$admin, $venue, venuesAdminPayload($venue)];
}

/**
 * @return array<string, mixed>
 */
function venuesAdminPayload(Venue $venue): array
{
    return [
        'name' => $venue->name,
        'slug' => $venue->slug,
        'addressLine1' => $venue->address_line1,
        'addressLine2' => null,
        'postalCode' => $venue->postal_code,
        'city' => $venue->city,
        'countryCode' => 'DE',
        'latitude' => null,
        'longitude' => null,
        'accessNotes' => null,
        'openingTimes' => [],
    ];
}

it('403s an admin without the manage-venues capability', function (): void {
    [, $admin] = venuesAdminOperator(['platform.access-admin', 'event.view-venues']);

    test()->withBrand();
    $venue = Venue::factory()->create();

    $this->actingAs($admin)
        ->get("http://admin.venuesadmin.test/admin/venues/{$venue->id}/edit")
        ->assertForbidden();
});

it('renders the edit page with the venue and the brand\'s pictures', function (): void {
    [$admin, $venue] = venuesAdminEditable();
    $picture = MediaItem::factory()->create(['brand_id' => $venue->brand_id]);

    $this->actingAs($admin)
        ->get("http://admin.venuesadmin.test/admin/venues/{$venue->id}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/venues/Edit')
            ->where('venue.name', 'Congress Centrum')
            ->where('venue.slug', 'congress-centrum')
            ->where('venue.brandId', $venue->brand_id)
            ->has('media', 1)
            ->where('media.0.id', $picture->id));
});

it('persists the details, the places and the pictures, and records the usages', function (): void {
    [$admin, $venue, $payload] = venuesAdminEditable();
    $first = MediaItem::factory()->create(['brand_id' => $venue->brand_id]);
    $second = MediaItem::factory()->create(['brand_id' => $venue->brand_id]);

    $this->actingAs($admin)
        ->put("http://admin.venuesadmin.test/admin/venues/{$venue->id}", [
            ...$payload,
            'slug' => 'ccb',
            'description' => 'A hall by the water.',
            'entryLatitude' => 53.5511,
            'entryLongitude' => 9.9937,
            'publicTransport' => 'S1 to Landungsbrücken.',
            'seatPlanReference' => 'Hall A, rows 1–24',
            'parkingSpots' => [['label' => 'Lot A', 'address' => 'Hafenstr. 1', 'latitude' => 53.55, 'longitude' => 9.99]],
            'loadingZones' => [['label' => 'Ramp North', 'address' => null, 'latitude' => null, 'longitude' => null]],
            'imageMediaIds' => [$first->id, $second->id],
            'primaryMediaId' => $second->id,
        ])
        ->assertRedirect("http://admin.venuesadmin.test/admin/venues/{$venue->id}/edit");

    $fresh = Venue::withoutBrandScope()->findOrFail($venue->id);

    expect($fresh->slug)->toBe('ccb')
        ->and($fresh->description)->toBe('A hall by the water.')
        ->and((float) $fresh->entry_latitude)->toBe(53.5511)
        ->and($fresh->seat_plan_reference)->toBe('Hall A, rows 1–24')
        ->and($fresh->parking_spots->first()->label)->toBe('Lot A')
        ->and($fresh->loading_zones->first()->label)->toBe('Ramp North')
        ->and($fresh->image_media_ids)->toBe([$first->id, $second->id])
        ->and($fresh->primary_media_id)->toBe($second->id)
        ->and($fresh->orderedImageMediaIds())->toBe([$second->id, $first->id]);

    foreach ([$first, $second] as $picture) {
        expect(MediaUsage::withoutBrandScope()
            ->where('media_item_id', $picture->id)
            ->where('module', 'event')
            ->where('entity_type', 'venue')
            ->where('entity_id', $venue->id)
            ->exists())->toBeTrue();
    }
});

it('releases the usage of a picture taken off the venue', function (): void {
    [$admin, $venue, $payload] = venuesAdminEditable();
    $first = MediaItem::factory()->create(['brand_id' => $venue->brand_id]);
    $second = MediaItem::factory()->create(['brand_id' => $venue->brand_id]);

    $usages = fn (string $mediaId): int => MediaUsage::withoutBrandScope()
        ->where('media_item_id', $mediaId)->where('entity_id', $venue->id)->count();

    $this->actingAs($admin)
        ->put("http://admin.venuesadmin.test/admin/venues/{$venue->id}", [
            ...$payload,
            'imageMediaIds' => [$first->id, $second->id],
            'primaryMediaId' => $first->id,
        ])->assertRedirect();

    $this->actingAs($admin)
        ->put("http://admin.venuesadmin.test/admin/venues/{$venue->id}", [
            ...$payload,
            'imageMediaIds' => [$second->id],
            'primaryMediaId' => $second->id,
        ])->assertRedirect();

    expect($usages($first->id))->toBe(0)
        ->and($usages($second->id))->toBe(1);

    $this->actingAs($admin)
        ->put("http://admin.venuesadmin.test/admin/venues/{$venue->id}", [...$payload, 'imageMediaIds' => []])
        ->assertRedirect();

    expect($usages($second->id))->toBe(0)
        ->and(Venue::withoutBrandScope()->findOrFail($venue->id)->primary_media_id)->toBeNull();
});

it('refuses a picture that belongs to another brand', function (): void {
    [$admin, $venue, $payload] = venuesAdminEditable();
    $other = Brand::factory()->withDomain('other-venue-brand.test')->create(['slug' => 'other-venue-brand']);
    $foreign = MediaItem::factory()->create(['brand_id' => $other->id]);

    $this->actingAs($admin)
        ->from("http://admin.venuesadmin.test/admin/venues/{$venue->id}/edit")
        ->put("http://admin.venuesadmin.test/admin/venues/{$venue->id}", [
            ...$payload,
            'imageMediaIds' => [$foreign->id],
            'primaryMediaId' => $foreign->id,
        ])
        ->assertSessionHasErrors('imageMediaIds');

    expect(Venue::withoutBrandScope()->findOrFail($venue->id)->image_media_ids)->toBe([]);
});

it('refuses a primary picture that is not one of the venue\'s own', function (): void {
    [$admin, $venue, $payload] = venuesAdminEditable();
    $listed = MediaItem::factory()->create(['brand_id' => $venue->brand_id]);
    $unlisted = MediaItem::factory()->create(['brand_id' => $venue->brand_id]);

    $this->actingAs($admin)
        ->from("http://admin.venuesadmin.test/admin/venues/{$venue->id}/edit")
        ->put("http://admin.venuesadmin.test/admin/venues/{$venue->id}", [
            ...$payload,
            'imageMediaIds' => [$listed->id],
            'primaryMediaId' => $unlisted->id,
        ])
        ->assertSessionHasErrors('primaryMediaId');
});

it('creates a venue from the dialog, derives a free slug and lands on its edit page', function (): void {
    [, $admin] = venuesAdminOperator(['platform.access-admin', 'event.manage-venues']);

    $brand = test()->withBrand();
    Venue::factory()->create(['name' => 'Main Hall', 'slug' => 'main-hall']);

    $this->actingAs($admin)
        ->post('http://admin.venuesadmin.test/admin/venues', [
            'brand_id' => $brand->getKey(),
            'name' => 'Main Hall',
            'addressLine1' => 'Hafenstr. 1',
            'addressLine2' => null,
            'postalCode' => '20359',
            'city' => 'Hamburg',
            'countryCode' => 'DE',
            'latitude' => null,
            'longitude' => null,
            'accessNotes' => null,
            'openingTimes' => [],
        ])
        ->assertRedirect();

    $created = Venue::withoutBrandScope()->where('name', 'Main Hall')->where('slug', 'main-hall-2')->first();

    expect($created)->not->toBeNull();
});

it('refuses a slug another venue of the brand already uses', function (): void {
    [$admin, $venue, $payload] = venuesAdminEditable();

    test()->withBrand(Brand::query()->findOrFail($venue->brand_id));
    Venue::factory()->create(['slug' => 'taken']);

    $this->actingAs($admin)
        ->from("http://admin.venuesadmin.test/admin/venues/{$venue->id}/edit")
        ->put("http://admin.venuesadmin.test/admin/venues/{$venue->id}", [...$payload, 'slug' => 'taken'])
        ->assertSessionHasErrors('slug');
});

/**
 * Round 4, Task 28: the edit page gained an "Opening times" card, so the
 * form now posts what used to only ride along untouched. `openingTimes` is
 * a list of `OpeningPeriodData` behind `OpeningTimesCast` — a list rather
 * than a seven-row grid because a site that closes for lunch says so with
 * two rows on the same day.
 */
it('round-trips a set of opening times through the edit form', function (): void {
    [$admin, $venue, $payload] = venuesAdminEditable();

    $this->actingAs($admin)
        ->put("http://admin.venuesadmin.test/admin/venues/{$venue->id}", [
            ...$payload,
            'openingTimes' => [
                ['dayOfWeek' => 1, 'opensAt' => '09:00', 'closesAt' => '12:00'],
                ['dayOfWeek' => 1, 'opensAt' => '13:00', 'closesAt' => '18:00'],
                ['dayOfWeek' => 6, 'opensAt' => '10:00', 'closesAt' => '16:00'],
            ],
        ])
        ->assertRedirect();

    $periods = Venue::withoutBrandScope()->findOrFail($venue->id)->opening_times;

    expect($periods)->toHaveCount(3)
        ->and($periods[0])->toBeInstanceOf(OpeningPeriodData::class)
        ->and($periods[0]->dayOfWeek)->toBe(1)
        ->and($periods[0]->closesAt)->toBe('12:00')
        ->and($periods[1]->opensAt)->toBe('13:00')
        ->and($periods[2]->dayOfWeek)->toBe(6);

    // And back out to the page the operator reloads.
    $this->actingAs($admin)
        ->get("http://admin.venuesadmin.test/admin/venues/{$venue->id}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('venue.openingTimes', 3)
            ->where('venue.openingTimes.1.opensAt', '13:00'));
});

it('reports an opening-times error on the row that carries it', function (): void {
    [$admin, $venue, $payload] = venuesAdminEditable();

    $this->actingAs($admin)
        ->from("http://admin.venuesadmin.test/admin/venues/{$venue->id}/edit")
        ->put("http://admin.venuesadmin.test/admin/venues/{$venue->id}", [
            ...$payload,
            'openingTimes' => [
                ['dayOfWeek' => 1, 'opensAt' => '09:00', 'closesAt' => '18:00'],
                ['dayOfWeek' => 9, 'opensAt' => 'noon', 'closesAt' => '18:00'],
            ],
        ])
        ->assertSessionHasErrors(['openingTimes.1.dayOfWeek', 'openingTimes.1.opensAt'])
        ->assertSessionDoesntHaveErrors(['openingTimes.0.opensAt']);

    expect(Venue::withoutBrandScope()->findOrFail($venue->id)->opening_times)->toHaveCount(0);
});

it('clears the opening times when the form sends none', function (): void {
    [$admin, $venue, $payload] = venuesAdminEditable();

    $venue->forceFill(['opening_times' => [
        new OpeningPeriodData(dayOfWeek: 1, opensAt: '09:00', closesAt: '18:00'),
    ]])->save();

    $this->actingAs($admin)
        ->put("http://admin.venuesadmin.test/admin/venues/{$venue->id}", $payload)
        ->assertRedirect();

    expect(Venue::withoutBrandScope()->findOrFail($venue->id)->opening_times)->toHaveCount(0);
});

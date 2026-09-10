<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Konventix\Resources\Enums\CapacityMode;
use Konventix\Resources\Enums\CategoryToken;
use Konventix\Resources\Models\Resource;
use Konventix\Resources\Models\ResourceType;

/**
 * Design §8.1 / §9.1 / §11: the category console (a "Kategorie" is a
 * `ResourceType`, design §4.1) on the operator host, its capability gate,
 * and the default roles `permissions:sync` materialises (ADR-0045).
 *
 * Helpers live in tests/Helpers.php with a `seating` prefix: Pest helpers
 * are process-global across every module (.ai/rules/tests.md).
 */
it('403s the category console without the manage-resource-types capability', function (): void {
    [, $admin] = seatingOperator(['platform.access-admin']);

    $this->actingAs($admin)
        ->get('http://admin.seating.test/admin/resource-types')
        ->assertForbidden();
});

it('materialises the planner and desk roles through permissions:sync', function (): void {
    // ADR-0045: the module declares, sync materialises — only for a brand
    // that has the module on, which is what `activateModule()` does.
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);

    $this->artisan('permissions:sync')->assertSuccessful();

    $planner = Role::query()->where('brand_id', $brand->getKey())->where('default_key', 'resources.planner')->first();
    $desk = Role::query()->where('brand_id', $brand->getKey())->where('default_key', 'resources.desk')->first();

    expect(PermissionModel::query()->where('name', 'resources.manage-plans')->exists())->toBeTrue()
        ->and($planner)->not->toBeNull()
        ->and($planner->name)->toBe('Planner')
        ->and($planner->permissions->pluck('name')->sort()->values()->all())->toBe([
            'resources.book-on-behalf',
            'resources.manage-plans',
            'resources.manage-resource-types',
            'resources.manage-resources',
            'resources.release-bookings',
            'resources.view-resources',
        ])
        ->and($desk)->not->toBeNull()
        ->and($desk->permissions->pluck('name')->sort()->values()->all())->toBe([
            'resources.book-on-behalf',
            'resources.release-bookings',
            'resources.view-resources',
        ]);
});

it('lists categories with the event name from the window read model and filters by event', function (): void {
    $brand = $this->withBrand();
    $sxlan = seatingEventWindow($brand, 'SXLan 2027');
    $other = seatingEventWindow($brand, 'Winter LAN');
    [, $admin] = seatingOperator();

    // Rows before the request: an admin-host request resolves no brand, so
    // a factory row created after it has nothing to be stamped with.
    $gamer = ResourceType::factory()->forEvent($sxlan)->create(['name' => 'Gamer', 'sort_order' => 2]);
    ResourceType::factory()->forEvent($sxlan)->create(['name' => 'Accessible', 'sort_order' => 1]);
    ResourceType::factory()->forEvent($other)->create(['name' => 'Booth']);

    // The carried decision (#282, design §12b): the list's place count is
    // the *bookable* one, because it is what an operator types into a
    // Registration quota's capacity. Three published, unblocked places
    // count; two unpublished and one published-but-blocked place of the
    // same category do not — proving a raw row count (six) is not what
    // reaches the screen.
    Resource::factory()->ofType($gamer)->published()->count(3)->create();
    Resource::factory()->ofType($gamer)->count(2)->create();
    Resource::factory()->ofType($gamer)->published()->create(['blocked' => true]);

    $this->actingAs($admin)
        ->get('http://admin.seating.test/admin/resource-types')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/resource-types/Index')
            ->has('types.data', 3)
            ->where('types.data.0.name', 'Accessible')
            ->where('types.data.0.eventName', 'SXLan 2027')
            ->where('types.data.1.name', 'Booth')
            ->where('types.data.2.name', 'Gamer')
            ->where('types.data.2.places', 3)
            ->has('events', 2));

    $this->actingAs($admin)
        ->get('http://admin.seating.test/admin/resource-types?event='.$other)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('types.data', 1)
            ->where('types.data.0.name', 'Booth')
            ->where('filters.event', $other));
});

it('creates a category with token and symbol under the chosen brand', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEventWindow($brand);
    [, $admin] = seatingOperator();

    $this->actingAs($admin)
        ->post('http://admin.seating.test/admin/resource-types', [
            'brand_id' => $brand->getKey(),
            'eventId' => $eventId,
            'name' => 'Gamer',
            'slug' => 'gamer',
            'mode' => 'unit',
            'holdMinutes' => 30,
            'description' => 'Table share with power and LAN.',
            'token' => 'chart-2',
            'symbol' => 'gamepad-2',
            'sortOrder' => 1,
            'defaultShape' => ['type' => 'rect', 'w' => 800, 'h' => 800],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $type = ResourceType::withoutBrandScope()->where('slug', 'gamer')->sole();

    expect($type->brand_id)->toBe($brand->getKey())
        ->and($type->event_id)->toBe($eventId)
        ->and($type->mode)->toBe(CapacityMode::Unit)
        ->and($type->token)->toBe(CategoryToken::Chart2)
        ->and($type->symbol)->toBe('gamepad-2')
        ->and($type->sort_order)->toBe(1)
        ->and($type->default_shape?->w)->toBe(800);
});

it('refuses an event that is not projected for the brand', function (): void {
    $brand = $this->withBrand();
    [, $admin] = seatingOperator();

    $this->actingAs($admin)
        ->post('http://admin.seating.test/admin/resource-types', [
            'brand_id' => $brand->getKey(),
            'eventId' => '01JZZZZZZZZZZZZZZZZZZZZZZZ',
            'name' => 'Nowhere',
            'slug' => 'nowhere',
            'mode' => 'unit',
            'holdMinutes' => 30,
            'token' => 'chart-1',
            'sortOrder' => 0,
        ])
        ->assertSessionHasErrors('eventId');

    expect(ResourceType::withoutBrandScope()->where('slug', 'nowhere')->exists())->toBeFalse();
});

it('refuses a slug already used for the event', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEventWindow($brand);
    [, $admin] = seatingOperator();

    ResourceType::factory()->forEvent($eventId)->create(['slug' => 'gamer']);

    $this->actingAs($admin)
        ->post('http://admin.seating.test/admin/resource-types', [
            'brand_id' => $brand->getKey(),
            'eventId' => $eventId,
            'name' => 'Gamer again',
            'slug' => 'gamer',
            'mode' => 'unit',
            'holdMinutes' => 30,
            'token' => 'chart-1',
            'sortOrder' => 0,
        ])
        ->assertSessionHasErrors('slug');
});

it('updates name, token and sort order and hands the editor the data object', function (): void {
    $brand = $this->withBrand();
    $eventId = seatingEventWindow($brand);
    [, $admin] = seatingOperator();

    $type = ResourceType::factory()->forEvent($eventId)->create([
        'name' => 'Gamer',
        'slug' => 'gamer',
        'token' => CategoryToken::Chart1,
        'sort_order' => 0,
    ]);

    $this->actingAs($admin)
        ->put('http://admin.seating.test/admin/resource-types/'.$type->getKey(), [
            'eventId' => $eventId,
            'name' => 'Gamer (power)',
            'slug' => 'gamer',
            'mode' => 'unit',
            'holdMinutes' => 45,
            'description' => null,
            'token' => 'accent',
            'symbol' => 'zap',
            'sortOrder' => 5,
            'defaultShape' => null,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect('http://admin.seating.test/admin/resource-types/'.$type->getKey().'/edit');

    expect($type->refresh()->name)->toBe('Gamer (power)')
        ->and($type->token)->toBe(CategoryToken::Accent)
        ->and($type->hold_minutes)->toBe(45)
        ->and($type->sort_order)->toBe(5);

    $this->actingAs($admin)
        ->get('http://admin.seating.test/admin/resource-types/'.$type->getKey().'/edit')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/resource-types/Edit')
            ->where('type.id', $type->getKey())
            ->where('type.name', 'Gamer (power)')
            ->where('type.token', 'accent')
            ->where('type.eventId', $eventId)
            ->where('places', 0)
            ->has('events', 1));
});

it('renders the category list and editor pages', function (): void {
    // A page added this session needs `npm run build` first: Inertia
    // resolves it through the Vite manifest and a missing entry looks like
    // a 500 (.ai/rules/tests.md).
    $brand = $this->withBrand();
    $eventId = seatingEventWindow($brand);
    [, $admin] = seatingOperator();

    $type = ResourceType::factory()->forEvent($eventId)->create(['name' => 'Gamer']);

    $this->actingAs($admin)
        ->get('http://admin.seating.test/admin/resource-types')
        ->assertOk()
        ->assertSee('Gamer');

    $this->actingAs($admin)
        ->get('http://admin.seating.test/admin/resource-types/'.$type->getKey().'/edit')
        ->assertOk()
        ->assertSee('Gamer');
});

it('contributes two admin navigation entries gated by capability', function (): void {
    // Admin-area entries skip the brand gate (there is no brand on the
    // admin host) and rely on their capability — `NavigationRegistry`.
    $entries = app(NavigationRegistry::class)->all()
        ->filter(fn (NavigationItem $item): bool => $item->module === 'resources' && $item->area === NavigationItem::AREA_ADMIN)
        ->values();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->label)->toBe('Categories')
        ->and($entries[0]->url)->toBe('/admin/resource-types')
        ->and($entries[0]->permission)->toBe('resources.manage-resource-types')
        ->and($entries[1]->label)->toBe('Seating plans')
        ->and($entries[1]->url)->toBe('/admin/plans')
        ->and($entries[1]->permission)->toBe('resources.manage-plans');
});

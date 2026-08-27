<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;
use Konventix\Platform\Features\BrandFeatureRegistry;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Konventix\Platform\Slots\SlotRegistry;
use Laravel\Pennant\Feature;
use Spatie\Permission\PermissionRegistrar;

/**
 * ADR-0047: a brand feature is a Pennant flag with no module behind it —
 * Commerce is Core and cannot be switched off, so the shop and the waiting
 * room opt in per brand through `commerce:shop`/`commerce:waiting-room`
 * instead. `BrandFeatureRegistry` is the machinery's own answer to "what
 * features exist"; the four module-activation enforcement points (routes,
 * navigation, slots) gain the same filter, keyed by feature instead of
 * module.
 */
function brandFeaturesOperator(array $permissions = [
    'platform.access-admin',
    'platform.view-brands',
    'platform.manage-module-activation',
]): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.brandfeatures.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => 'Feature Editor',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($permissions);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin];
}

it("lists Commerce's two features with labels", function (): void {
    $features = collect(app(BrandFeatureRegistry::class)->all());

    expect($features->pluck('name')->all())->toContain('commerce:shop', 'commerce:waiting-room')
        ->and($features->firstWhere('name', 'commerce:shop')->label)->toBe('Shop')
        ->and($features->firstWhere('name', 'commerce:waiting-room')->label)->toBe('Waiting room');
});

it('activates a feature for one brand only and ignores an unknown name', function (): void {
    [, $admin] = brandFeaturesOperator();

    $brand = Brand::factory()->create(['slug' => 'feat-a']);
    $otherBrand = Brand::factory()->create(['slug' => 'feat-b']);

    $this->actingAs($admin)
        ->put("http://admin.brandfeatures.test/admin/brands/{$brand->getKey()}/features", [
            'features' => [
                'commerce:shop' => true,
                'made:up' => true,
            ],
        ])
        ->assertRedirect();

    expect(Feature::for($brand)->active('commerce:shop'))->toBeTrue()
        ->and(Feature::for($otherBrand)->active('commerce:shop'))->toBeFalse();

    // Switching off again deactivates.
    $this->actingAs($admin)
        ->put("http://admin.brandfeatures.test/admin/brands/{$brand->getKey()}/features", [
            'features' => ['commerce:shop' => false],
        ])
        ->assertRedirect();

    expect(Feature::for($brand)->active('commerce:shop'))->toBeFalse();
});

it('requires the module-activation capability for the features endpoint', function (): void {
    [, $admin] = brandFeaturesOperator(permissions: [
        'platform.access-admin',
        'platform.view-brands',
    ]);

    $brand = Brand::factory()->create(['slug' => 'feat-no-perm']);

    $this->actingAs($admin)
        ->put("http://admin.brandfeatures.test/admin/brands/{$brand->getKey()}/features", [
            'features' => ['commerce:shop' => true],
        ])
        ->assertForbidden();
});

it('404s a route behind an inactive brand feature and 200s once active', function (): void {
    $brand = Brand::factory()->create()->refresh();
    $brand->domains()->create(['host' => 'feature-gate.test', 'is_primary' => true]);

    Route::middleware(['web', 'ensure-feature:commerce:shop'])
        ->get('/feature-probe', fn (): string => 'ok');

    $this->get('http://feature-gate.test/feature-probe')->assertNotFound();

    Feature::for($brand)->activate('commerce:shop');

    $this->get('http://feature-gate.test/feature-probe')->assertOk()->assertSee('ok');
});

it('hides a navigation item behind an inactive brand feature', function (): void {
    $brand = $this->withBrand();

    $registry = app(NavigationRegistry::class);
    $registry->add(new NavigationItem(
        module: 'commerce',
        label: 'Shop',
        url: '/shop',
        feature: 'commerce:shop',
    ));

    expect($registry->visible()->pluck('label')->contains('Shop'))->toBeFalse();

    Feature::for($brand)->activate('commerce:shop');

    expect($registry->visible()->pluck('label')->contains('Shop'))->toBeTrue();
});

it('skips a slot contribution behind an inactive brand feature', function (): void {
    $brand = $this->withBrand();

    $slots = app(SlotRegistry::class);
    $slots->add(
        slot: 'test.brand-feature.slot',
        module: 'commerce',
        component: 'ShopWidget',
        feature: 'commerce:shop',
    );

    expect($slots->for('test.brand-feature.slot')->pluck('component')->all())->toBe([]);

    Feature::for($brand)->activate('commerce:shop');

    expect($slots->for('test.brand-feature.slot')->pluck('component')->all())->toBe(['ShopWidget']);
});

it('renders the edit page with feature state', function (): void {
    [, $admin] = brandFeaturesOperator();

    $brand = Brand::factory()->create(['slug' => 'feat-edit']);
    $brand->domains()->create(['host' => 'feat-edit.brandfeatures.test', 'is_primary' => true]);

    Feature::for($brand)->activate('commerce:shop');

    $this->actingAs($admin)
        ->get("http://admin.brandfeatures.test/admin/brands/{$brand->getKey()}/edit")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $page->component('admin/brands/Edit')->has('features');

            $features = collect($page->toArray()['props']['features']);

            expect($features->firstWhere('name', 'commerce:shop'))
                ->toMatchArray(['name' => 'commerce:shop', 'label' => 'Shop', 'active' => true])
                ->and($features->firstWhere('name', 'commerce:waiting-room')['active'])->toBeFalse();
        });
});

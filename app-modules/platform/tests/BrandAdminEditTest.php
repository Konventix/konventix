<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Modules\ModuleGate;
use Laravel\Pennant\Feature;
use Spatie\Permission\PermissionRegistrar;

/**
 * Brand editing in the operator console: identity fields, host sync, and
 * per-brand module activation (ADR-0003) — the Pennant state behind all
 * four enforcement points.
 */
function brandsEditOperator(array $permissions = [
    'platform.access-admin',
    'platform.view-brands',
    'platform.manage-brands',
    'platform.manage-module-activation',
]): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.brandedit.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => 'Brand Editor',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($permissions);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin];
}

it('renders the edit page with hosts and per-brand module state', function (): void {
    [, $admin] = brandsEditOperator();

    $brand = Brand::factory()->create(['name' => 'SXLan', 'slug' => 'sxlan-edit']);
    $brand->domains()->create(['host' => 'sxlan-edit.test', 'is_primary' => true]);

    Feature::for($brand)->activate(app(ModuleGate::class)->featureName('staffing'));

    $this->actingAs($admin)
        ->get("http://admin.brandedit.test/admin/brands/{$brand->getKey()}/edit")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($brand): void {
            $page->component('admin/brands/Edit')
                ->where('brand.id', $brand->getKey())
                ->where('brand.hosts.0', 'sxlan-edit.test')
                ->has('modules')
                ->has('alwaysOn');

            $modules = collect($page->toArray()['props']['modules']);
            expect($modules->firstWhere('module', 'staffing')['active'])->toBeTrue();

            // Kernel/core modules never appear as toggles.
            expect($modules->pluck('module')->all())->not->toContain('platform', 'messaging');
        });
});

it('updates brand fields and syncs hosts with the first as primary', function (): void {
    [, $admin] = brandsEditOperator();

    $brand = Brand::factory()->create(['slug' => 'update-me']);
    $brand->domains()->create(['host' => 'old.brandedit.test', 'is_primary' => true]);
    $brand->domains()->create(['host' => 'keep.brandedit.test', 'is_primary' => false]);

    $this->actingAs($admin)
        ->put("http://admin.brandedit.test/admin/brands/{$brand->getKey()}", [
            'name' => 'Renamed',
            'slug' => 'renamed',
            'currency' => 'CHF',
            'hosts' => ['new.brandedit.test', 'keep.brandedit.test'],
        ])
        ->assertRedirect();

    $brand->refresh();
    expect($brand->name)->toBe('Renamed')
        ->and($brand->slug)->toBe('renamed')
        ->and($brand->currency)->toBe('CHF');

    $hosts = $brand->domains()->orderByDesc('is_primary')->get();
    expect($hosts->pluck('host')->all())->toBe(['new.brandedit.test', 'keep.brandedit.test'])
        ->and($hosts[0]->is_primary)->toBeTrue()
        ->and($hosts->pluck('host'))->not->toContain('old.brandedit.test');
});

it('refuses a host another brand already answers on', function (): void {
    [, $admin] = brandsEditOperator();

    $other = Brand::factory()->create(['slug' => 'other-brand']);
    $other->domains()->create(['host' => 'taken.brandedit.test', 'is_primary' => true]);

    $brand = Brand::factory()->create(['slug' => 'mine-brand']);
    $brand->domains()->create(['host' => 'mine.brandedit.test', 'is_primary' => true]);

    $this->actingAs($admin)
        ->from("http://admin.brandedit.test/admin/brands/{$brand->getKey()}/edit")
        ->put("http://admin.brandedit.test/admin/brands/{$brand->getKey()}", [
            'name' => $brand->name,
            'slug' => $brand->slug,
            'currency' => 'EUR',
            'hosts' => ['taken.brandedit.test'],
        ])
        ->assertSessionHasErrors('hosts');

    expect($brand->domains()->pluck('host')->all())->toBe(['mine.brandedit.test']);
});

it('toggles module activation for this brand only, ignoring forged keys', function (): void {
    [, $admin] = brandsEditOperator();

    $gate = app(ModuleGate::class);
    $brand = Brand::factory()->create(['slug' => 'features-a']);
    $otherBrand = Brand::factory()->create(['slug' => 'features-b']);

    $this->actingAs($admin)
        ->put("http://admin.brandedit.test/admin/brands/{$brand->getKey()}/modules", [
            'modules' => [
                'staffing' => true,
                // Kernel module — forged toggle must change nothing.
                'messaging' => false,
                'not-a-module' => true,
            ],
        ])
        ->assertRedirect();

    expect(Feature::for($brand)->active($gate->featureName('staffing')))->toBeTrue()
        ->and(Feature::for($otherBrand)->active($gate->featureName('staffing')))->toBeFalse();

    // Switching off again deactivates.
    $this->actingAs($admin)
        ->put("http://admin.brandedit.test/admin/brands/{$brand->getKey()}/modules", [
            'modules' => ['staffing' => false],
        ])
        ->assertRedirect();

    expect(Feature::for($brand)->active($gate->featureName('staffing')))->toBeFalse();
});

it('requires the module-activation capability for the modules endpoint', function (): void {
    [, $admin] = brandsEditOperator(permissions: [
        'platform.access-admin',
        'platform.view-brands',
        'platform.manage-brands',
    ]);

    $brand = Brand::factory()->create(['slug' => 'no-perm']);

    $this->actingAs($admin)
        ->put("http://admin.brandedit.test/admin/brands/{$brand->getKey()}/modules", [
            'modules' => ['staffing' => true],
        ])
        ->assertForbidden();
});

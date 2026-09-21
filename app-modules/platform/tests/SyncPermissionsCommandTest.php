<?php

declare(strict_types=1);

use Konventix\Platform\Actions\MaterializeOperatorAdminRole;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Permissions\PermissionCatalog;

/**
 * The first test the command ever had. ADR-0013: rows are derived from the
 * enums, orphans are reported and never deleted; ADR-0045: default roles are
 * materialized per brand in the same run.
 */
it('creates one permission row per declared capability and reports an orphan', function (): void {
    Permission::query()->create(['name' => 'ghost.capability', 'guard_name' => 'web']);

    $this->artisan('permissions:sync', ['--no-roles' => true])
        ->expectsOutputToContain('Orphaned permission row [ghost.capability]')
        ->assertSuccessful();

    expect(Permission::query()->where('guard_name', 'web')->whereNot('name', 'ghost.capability')->count())
        ->toBe(count(app(PermissionCatalog::class)->all()))
        ->and(Permission::query()->where('name', 'ghost.capability')->exists())->toBeTrue();
});

it('materializes the default roles of every brand', function (): void {
    $sxlan = Brand::factory()->create(['slug' => 'sync-sxlan']);
    $xpertise = Brand::factory()->create(['slug' => 'sync-xpertise']);

    $this->artisan('permissions:sync')
        ->expectsOutputToContain('Default roles for ['.$sxlan->name.']')
        ->assertSuccessful();

    foreach ([$sxlan, $xpertise] as $brand) {
        expect(Role::query()->where('brand_id', $brand->getKey())->where('default_key', 'platform.brand-admin')->exists())->toBeTrue()
            ->and(Role::query()->where('brand_id', $brand->getKey())->where('default_key', 'registration.desk')->exists())->toBeTrue();
    }
});

it('creates no role with --no-roles', function (): void {
    $brand = Brand::factory()->create(['slug' => 'sync-none']);

    $this->artisan('permissions:sync', ['--no-roles' => true])->assertSuccessful();

    expect(Role::query()->where('brand_id', $brand->getKey())->count())->toBe(0);
});

/**
 * The operator scope's seeded role (hotfix 2026-09-13). "Operator Admin" was
 * granted everything once, by `konventix:install`, and never revisited —
 * every capability a later release added was a 403 on the admin host for the
 * operator who installed the instance (the seating console, 0.6.0, on dev).
 */
it('re-grants an untouched Operator Admin role every capability, stamping the default key it predates', function (): void {
    $operator = Operator::factory()->create();
    $stale = Role::query()->create(['name' => 'Operator Admin', 'guard_name' => 'web', 'brand_id' => $operator->getKey()]);
    $stale->syncPermissions([Permission::query()->firstOrCreate(['name' => 'platform.access-admin', 'guard_name' => 'web'])]);

    $this->artisan('permissions:sync')
        ->expectsOutputToContain('Operator Admin for ['.$operator->name.']: updated')
        ->assertSuccessful();

    $stale->refresh();

    expect($stale->default_key)->toBe(MaterializeOperatorAdminRole::DEFAULT_KEY)
        ->and($stale->permissions()->count())->toBe(Permission::query()->where('guard_name', 'web')->count())
        ->and($stale->hasPermissionTo('resources.manage-plans'))->toBeTrue();
});

it('leaves a customized Operator Admin role exactly as the operator left it', function (): void {
    $operator = Operator::factory()->create();
    $narrowed = Role::query()->create([
        'name' => 'Operator Admin',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
        'default_key' => MaterializeOperatorAdminRole::DEFAULT_KEY,
        'customized_at' => now(),
    ]);
    $narrowed->syncPermissions([Permission::query()->firstOrCreate(['name' => 'platform.access-admin', 'guard_name' => 'web'])]);

    $this->artisan('permissions:sync')
        ->expectsOutputToContain('Operator Admin for ['.$operator->name.']: skipped')
        ->assertSuccessful();

    expect($narrowed->refresh()->permissions()->count())->toBe(1);
});

it('creates the Operator Admin role for an operator that has none', function (): void {
    $operator = Operator::factory()->create();

    $this->artisan('permissions:sync')
        ->expectsOutputToContain('Operator Admin for ['.$operator->name.']: created')
        ->assertSuccessful();

    $role = Role::query()->where('brand_id', $operator->getKey())->where('default_key', MaterializeOperatorAdminRole::DEFAULT_KEY)->first();

    expect($role)->not->toBeNull()
        ->and($role->permissions()->count())->toBe(Permission::query()->where('guard_name', 'web')->count());
});

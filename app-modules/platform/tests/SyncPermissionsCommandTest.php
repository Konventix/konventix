<?php

declare(strict_types=1);

use Konventix\Platform\Models\Brand;
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

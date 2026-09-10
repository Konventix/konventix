<?php

declare(strict_types=1);

use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
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

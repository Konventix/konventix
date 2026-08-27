<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Permissions\PermissionHolders;

/**
 * Task 4 (ADR-0048 point 3): Commerce's operations notice needs "every user
 * who can manage this brand's products" without a user model of its own —
 * `PermissionHolders` answers that as a pure query over spatie's own pivot
 * tables (`role_has_permissions` carries no team column, so granting a
 * permission to a brand-scoped role needs no `PermissionRegistrar` team
 * context — only `model_has_roles`/`roles` are team-scoped).
 */
function permHoldersRole(string $brandId, string $permission, string $name = 'Shop Manager'): Role
{
    PermissionModel::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);

    $role = Role::query()->create([
        'name' => $name,
        'guard_name' => 'web',
        'brand_id' => $brandId,
    ]);
    $role->givePermissionTo($permission);

    return $role;
}

function permHoldersGrant(Role $role, User $user, string $brandId): void
{
    DB::table('model_has_roles')->insert([
        'role_id' => $role->id,
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->id,
        'brand_id' => $brandId,
    ]);
}

it('finds exactly the users holding a permission on the given brand', function (): void {
    $brandA = Brand::factory()->create();
    $brandB = Brand::factory()->create();

    $roleA = permHoldersRole($brandA->id, 'commerce.manage-products');
    $roleB = permHoldersRole($brandB->id, 'commerce.manage-products');

    $memberOne = User::factory()->create();
    $memberTwo = User::factory()->create();
    $otherBrandMember = User::factory()->create();
    $noRoleUser = User::factory()->create();

    permHoldersGrant($roleA, $memberOne, $brandA->id);
    permHoldersGrant($roleA, $memberTwo, $brandA->id);
    permHoldersGrant($roleB, $otherBrandMember, $brandB->id);

    $holders = app(PermissionHolders::class)->userIdsWithPermission($brandA->id, 'commerce.manage-products');

    expect(collect($holders)->sort()->values()->all())
        ->toBe(collect([$memberOne->id, $memberTwo->id])->sort()->values()->all())
        ->and($holders)->not->toContain($otherBrandMember->id)
        ->and($holders)->not->toContain($noRoleUser->id);
});

it('excludes a brand role that does not grant the permission asked for', function (): void {
    $brand = Brand::factory()->create();
    $role = permHoldersRole($brand->id, 'commerce.view-orders', 'Viewer');
    $user = User::factory()->create();
    permHoldersGrant($role, $user, $brand->id);

    $holders = app(PermissionHolders::class)->userIdsWithPermission($brand->id, 'commerce.manage-products');

    expect($holders)->toBe([]);
});

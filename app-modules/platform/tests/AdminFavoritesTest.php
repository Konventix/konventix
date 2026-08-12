<?php

declare(strict_types=1);

use Konventix\Identity\Models\User;
use Konventix\Platform\Models\AdminFavorite;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ADR-0029: admin favorites are a personal, per-user preference on the
 * operator console — ported concept from LanCore's NavFavorites +
 * SidebarFavoriteController. Gating is the shared platform.access-admin
 * capability the operator host already enforces; there is no separate
 * favorites permission.
 */
function favoritesAdminOperator(): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.favorites.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    PermissionModel::query()->firstOrCreate([
        'name' => 'platform.access-admin',
        'guard_name' => 'web',
    ]);

    $role = Role::query()->create([
        'name' => 'Operator Admin',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo('platform.access-admin');

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin, $role];
}

it('lets an admin pin a favorite', function (): void {
    [, $admin] = favoritesAdminOperator();

    $this->actingAs($admin)
        ->post('http://admin.favorites.test/admin/favorites', [
            'label' => 'Brands',
            'url' => '/admin/brands',
        ])
        ->assertRedirect();

    expect(
        AdminFavorite::query()
            ->where('user_id', $admin->getKey())
            ->where('url', '/admin/brands')
            ->exists(),
    )->toBeTrue();
});

it('is idempotent when pinning the same url twice', function (): void {
    [, $admin] = favoritesAdminOperator();

    $payload = ['label' => 'Brands', 'url' => '/admin/brands'];

    $this->actingAs($admin)->post('http://admin.favorites.test/admin/favorites', $payload);
    $this->actingAs($admin)->post('http://admin.favorites.test/admin/favorites', $payload);

    expect(AdminFavorite::query()->where('user_id', $admin->getKey())->count())->toBe(1);
});

it('lets an admin unpin their own favorite', function (): void {
    [, $admin] = favoritesAdminOperator();

    $favorite = AdminFavorite::factory()->create(['user_id' => $admin->getKey()]);

    $this->actingAs($admin)
        ->delete("http://admin.favorites.test/admin/favorites/{$favorite->getKey()}")
        ->assertRedirect();

    expect(AdminFavorite::query()->whereKey($favorite->getKey())->exists())->toBeFalse();
});

it('isolates favorites per user: an admin cannot unpin another admin\'s favorite', function (): void {
    [$operator, $admin, $role] = favoritesAdminOperator();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());
    $otherAdmin = User::factory()->create();
    $otherAdmin->assignRole($role);
    $registrar->setPermissionsTeamId(null);

    $favorite = AdminFavorite::factory()->create(['user_id' => $admin->getKey()]);

    $this->actingAs($otherAdmin)
        ->delete("http://admin.favorites.test/admin/favorites/{$favorite->getKey()}")
        ->assertNotFound();

    expect(AdminFavorite::query()->whereKey($favorite->getKey())->exists())->toBeTrue();
});

it('requires the admin capability to pin a favorite', function (): void {
    favoritesAdminOperator();

    $this->actingAs(User::factory()->create())
        ->post('http://admin.favorites.test/admin/favorites', [
            'label' => 'Brands',
            'url' => '/admin/brands',
        ])
        ->assertForbidden();
});

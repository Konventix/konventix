<?php

declare(strict_types=1);

use Illuminate\Support\Str;
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

it('appends a new pin to the end of the order', function (): void {
    [, $admin] = favoritesAdminOperator();

    AdminFavorite::factory()->create([
        'user_id' => $admin->getKey(),
        'url' => '/admin/brands',
        'position' => 7,
    ]);

    $this->actingAs($admin)->post('http://admin.favorites.test/admin/favorites', [
        'label' => 'Users',
        'url' => '/admin/users',
    ]);

    expect(
        AdminFavorite::query()
            ->where('user_id', $admin->getKey())
            ->where('url', '/admin/users')
            ->value('position'),
    )->toBe(8);
});

it('persists a drag-reorder of the favorites', function (): void {
    [, $admin] = favoritesAdminOperator();

    $first = AdminFavorite::factory()->create(['user_id' => $admin->getKey(), 'position' => 1]);
    $second = AdminFavorite::factory()->create(['user_id' => $admin->getKey(), 'position' => 2]);
    $third = AdminFavorite::factory()->create(['user_id' => $admin->getKey(), 'position' => 3]);

    $this->actingAs($admin)
        ->put('http://admin.favorites.test/admin/favorites/order', [
            'ids' => [$third->getKey(), $first->getKey(), $second->getKey()],
        ])
        ->assertRedirect();

    expect(
        AdminFavorite::query()
            ->where('user_id', $admin->getKey())
            ->inUserOrder()
            ->pluck('id')
            ->all(),
    )->toBe([$third->getKey(), $first->getKey(), $second->getKey()]);
});

it('ignores ids belonging to another user when reordering', function (): void {
    [$operator, $admin, $role] = favoritesAdminOperator();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());
    $otherAdmin = User::factory()->create();
    $otherAdmin->assignRole($role);
    $registrar->setPermissionsTeamId(null);

    $own = AdminFavorite::factory()->create(['user_id' => $admin->getKey(), 'position' => 5]);
    $foreign = AdminFavorite::factory()->create(['user_id' => $otherAdmin->getKey(), 'position' => 9]);

    $this->actingAs($admin)
        ->put('http://admin.favorites.test/admin/favorites/order', [
            'ids' => [$foreign->getKey(), $own->getKey()],
        ])
        ->assertRedirect();

    expect($own->refresh()->position)->toBe(1)
        ->and($foreign->refresh()->position)->toBe(9);
});

it('rejects a reorder without ids', function (): void {
    [, $admin] = favoritesAdminOperator();

    $this->actingAs($admin)
        ->put('http://admin.favorites.test/admin/favorites/order', ['ids' => []])
        ->assertSessionHasErrors('ids');
});

it('requires the admin capability to reorder favorites', function (): void {
    favoritesAdminOperator();

    $this->actingAs(User::factory()->create())
        ->put('http://admin.favorites.test/admin/favorites/order', [
            'ids' => [(string) Str::ulid()],
        ])
        ->assertForbidden();
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

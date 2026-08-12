<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Server-side data-table support for the operator console's user list
 * (ADR-0029): `search`, `sort`, `dir`, `per_page` query params, mirrored by
 * the frontend `useDataTable` composable. Gate::authorize(ViewUsers) stays
 * intact under the query filtering.
 */
function usersAdminOperator(array $permissions = ['platform.access-admin', 'identity.view-users']): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.usersidx.test'])->save();

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

it('filters users by search term across name and email', function (): void {
    [, $admin] = usersAdminOperator();

    User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);
    User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.test']);

    $this->actingAs($admin)
        ->get('http://admin.usersidx.test/admin/users?search=ada')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->count('users.data', 1)
            ->where('users.data.0.email', 'ada@example.test')
            ->where('filters.search', 'ada'));
});

it('sorts users by the requested column and direction', function (): void {
    [, $admin] = usersAdminOperator();

    User::factory()->create(['name' => 'Alice Example']);
    User::factory()->create(['name' => 'Bob Example']);

    $this->actingAs($admin)
        ->get('http://admin.usersidx.test/admin/users?sort=name&dir=desc&per_page=100')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.sort', 'name')
            ->where('filters.dir', 'desc')
            ->where('users.data', function (Collection $users): bool {
                $names = $users->pluck('name')->values()->all();
                $bobIndex = array_search('Bob Example', $names, true);
                $aliceIndex = array_search('Alice Example', $names, true);

                return $bobIndex !== false && $aliceIndex !== false && $bobIndex < $aliceIndex;
            }));
});

it('paginates users per the per_page query param', function (): void {
    [, $admin] = usersAdminOperator();

    User::factory()->count(3)->create();

    $this->actingAs($admin)
        ->get('http://admin.usersidx.test/admin/users?per_page=2')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->count('users.data', 2)
            ->where('filters.per_page', 2));
});

it('still enforces the view-users capability under query filtering', function (): void {
    [, $admin] = usersAdminOperator(['platform.access-admin']);

    $this->actingAs($admin)
        ->get('http://admin.usersidx.test/admin/users?search=anything')
        ->assertForbidden();
});

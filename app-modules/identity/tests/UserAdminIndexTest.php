<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
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

    // Named rather than faked: the search test counts matches, and a faker
    // name that happened to contain the search term ("Nadja" for "ada") made
    // the suite fail on some runs and not others.
    $admin = User::factory()->create([
        'name' => 'Console Operator',
        'email' => 'console-operator@usersidx.test',
    ]);
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

it('defaults the brand filter to the console selection and lets the list clear it', function (): void {
    [, $admin] = usersAdminOperator();

    // Accounts are operator-global (ADR-0016) — a brand narrows the list
    // through membership, not through the account itself.
    $sxlan = $this->withBrand(Brand::factory()->create(['name' => 'SXLan']));
    $ada = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);
    BrandMembership::factory()->create(['user_id' => $ada->getKey()]);

    $this->withBrand(Brand::factory()->create(['name' => 'Xpertise']));
    $grace = User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.test']);
    BrandMembership::factory()->create(['user_id' => $grace->getKey()]);

    $this->actingAs($admin)
        ->put('http://admin.usersidx.test/admin/selection', ['brandId' => $sxlan->getKey(), 'eventId' => null])
        ->assertRedirect();

    $this->actingAs($admin)
        ->get('http://admin.usersidx.test/admin/users')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.brand', $sxlan->getKey())
            ->count('users.data', 1)
            ->where('users.data.0.email', 'ada@example.test'));

    // A default, not a scope: "all brands" is a legitimate console view.
    $this->actingAs($admin)
        ->get('http://admin.usersidx.test/admin/users?brand=all')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.brand', null)
            ->count('users.data', 3));
});

<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Server-side data-table support for the operator console's brand list
 * (ADR-0029): `search`, `sort`, `dir`, `per_page` query params, mirrored by
 * the frontend `useDataTable` composable. Gate::authorize(ViewBrands) stays
 * intact under the query filtering.
 */
function brandsAdminOperator(array $permissions = ['platform.access-admin', 'platform.view-brands']): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.brandsidx.test'])->save();

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

it('filters brands by search term across name and slug', function (): void {
    [, $admin] = brandsAdminOperator();

    Brand::factory()->create(['name' => 'SXLan', 'slug' => 'sxlan']);
    Brand::factory()->create(['name' => 'Xpertise', 'slug' => 'xpertise']);

    $this->actingAs($admin)
        ->get('http://admin.brandsidx.test/admin/brands?search=sxlan')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->count('brands.data', 1)
            ->where('brands.data.0.slug', 'sxlan')
            ->where('filters.search', 'sxlan'));
});

it('sorts brands by the requested column and direction', function (): void {
    [, $admin] = brandsAdminOperator();

    // Zz…/Aa… prefixes keep the assertion stable against the base TestCase's
    // seeded localhost brand, whose faker name sorts unpredictably.
    Brand::factory()->create(['name' => 'Aa Alpha', 'slug' => 'alpha']);
    Brand::factory()->create(['name' => 'Zz Beta', 'slug' => 'beta']);

    $this->actingAs($admin)
        ->get('http://admin.brandsidx.test/admin/brands?sort=name&dir=desc')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('brands.data.0.name', 'Zz Beta')
            ->where('filters.sort', 'name')
            ->where('filters.dir', 'desc'));

    $this->actingAs($admin)
        ->get('http://admin.brandsidx.test/admin/brands?sort=name&dir=asc')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('brands.data.0.name', 'Aa Alpha'));
});

it('ignores an unlisted sort column and falls back to the default', function (): void {
    [, $admin] = brandsAdminOperator();

    $this->actingAs($admin)
        ->get('http://admin.brandsidx.test/admin/brands?sort=theme_tokens&dir=desc')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.sort', 'name'));
});

it('paginates brands per the per_page query param', function (): void {
    [, $admin] = brandsAdminOperator();

    Brand::factory()->count(3)->create();

    // +1: the base TestCase seeds a localhost brand for every HTTP test.
    $this->actingAs($admin)
        ->get('http://admin.brandsidx.test/admin/brands?per_page=2')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->count('brands.data', 2)
            ->where('brands.total', 4)
            ->where('filters.per_page', 2));
});

it('still enforces the view-brands capability under query filtering', function (): void {
    [, $admin] = brandsAdminOperator(['platform.access-admin']);

    $this->actingAs($admin)
        ->get('http://admin.brandsidx.test/admin/brands?search=anything')
        ->assertForbidden();
});

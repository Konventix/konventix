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
 * Role CRUD in the operator console (ADR-0013: capabilities are enums,
 * roles are data): create per brand, grant against the discovered
 * catalogue, refuse what no module declares.
 */
function roleEditorOperator(array $permissions = ['platform.access-admin', 'platform.manage-roles']): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.roleedit.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => 'Role Editor',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($permissions);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin];
}

it('creates a role for the brand and sends the operator to the grant screen', function (): void {
    [, $admin] = roleEditorOperator();
    $brand = Brand::factory()->create(['slug' => 're-create']);

    $response = $this->actingAs($admin)
        ->post('http://admin.roleedit.test/admin/roles', [
            'brand_id' => $brand->getKey(),
            'name' => 'Door Crew',
        ]);

    $role = Role::query()->where('brand_id', $brand->getKey())->where('name', 'Door Crew')->sole();
    $response->assertRedirect("http://admin.roleedit.test/admin/roles/{$role->getKey()}/edit");
});

it('refuses a duplicate role name within one brand', function (): void {
    [, $admin] = roleEditorOperator();
    $brand = Brand::factory()->create(['slug' => 're-dupe']);

    Role::query()->create(['name' => 'Door Crew', 'guard_name' => 'web', 'brand_id' => $brand->getKey()]);

    $this->actingAs($admin)
        ->post('http://admin.roleedit.test/admin/roles', [
            'brand_id' => $brand->getKey(),
            'name' => 'Door Crew',
        ])
        ->assertSessionHasErrors('name');
});

it('renders the grant screen from the discovered catalogue', function (): void {
    [, $admin] = roleEditorOperator();
    $brand = Brand::factory()->create(['name' => 'SXLan', 'slug' => 're-edit']);

    $role = Role::query()->create(['name' => 'Door Crew', 'guard_name' => 'web', 'brand_id' => $brand->getKey()]);

    $this->actingAs($admin)
        ->get("http://admin.roleedit.test/admin/roles/{$role->getKey()}/edit")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $page->component('admin/roles/Edit')
                ->where('role.name', 'Door Crew')
                ->where('role.brandName', 'SXLan')
                ->has('catalog');

            $catalog = collect($page->toArray()['props']['catalog']);
            expect($catalog->firstWhere('module', 'platform')['permissions'])
                ->toContain('platform.manage-roles')
                ->and($catalog->firstWhere('module', 'admission')['permissions'])
                ->toContain('admission.scan-check-in');
        });
});

it('renames a role and syncs its grants, refusing undeclared capabilities', function (): void {
    [, $admin] = roleEditorOperator();
    $brand = Brand::factory()->create(['slug' => 're-update']);

    $role = Role::query()->create(['name' => 'Door Crew', 'guard_name' => 'web', 'brand_id' => $brand->getKey()]);

    $this->actingAs($admin)
        ->put("http://admin.roleedit.test/admin/roles/{$role->getKey()}", [
            'name' => 'Entrance Team',
            'permissions' => ['admission.scan-check-in', 'commerce.view-orders'],
        ])
        ->assertRedirect();

    $role->refresh();
    expect($role->name)->toBe('Entrance Team')
        ->and($role->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['admission.scan-check-in', 'commerce.view-orders']);

    // A grant no module declares is refused — the catalogue is the law.
    $this->actingAs($admin)
        ->put("http://admin.roleedit.test/admin/roles/{$role->getKey()}", [
            'name' => 'Entrance Team',
            'permissions' => ['made.up-capability'],
        ])
        ->assertSessionHasErrors('permissions');

    expect($role->refresh()->permissions)->toHaveCount(2);
});

it('requires manage-roles for the editor', function (): void {
    [, $admin] = roleEditorOperator(permissions: ['platform.access-admin']);
    $brand = Brand::factory()->create(['slug' => 're-perm']);

    $role = Role::query()->create(['name' => 'Door Crew', 'guard_name' => 'web', 'brand_id' => $brand->getKey()]);

    $this->actingAs($admin)
        ->get("http://admin.roleedit.test/admin/roles/{$role->getKey()}/edit")
        ->assertForbidden();
});

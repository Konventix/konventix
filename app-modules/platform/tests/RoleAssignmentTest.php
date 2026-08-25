<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Decision log Round 6 D-BATCH: Platform owns the role-assignment screen;
 * users are referenced by ULID, no Identity model import in src/ (the
 * controller resolves the pivot through spatie's own morph relation).
 */
function rolesOperator(array $permissions = ['platform.access-admin', 'platform.manage-roles']): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.roles.test'])->save();

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

function brandRole(string $brandId, string $name = 'Event Manager'): Role
{
    return Role::query()->create([
        'name' => $name,
        'guard_name' => 'web',
        'brand_id' => $brandId,
    ]);
}

it('lists a brand role with its members on the index', function (): void {
    [, $admin] = rolesOperator();

    $brand = $this->withBrand();
    $role = brandRole($brand->id);
    $member = User::factory()->create();

    DB::table('model_has_roles')->insert([
        'role_id' => $role->id,
        'model_type' => $member->getMorphClass(),
        'model_id' => $member->id,
        'brand_id' => $brand->id,
    ]);

    $this->actingAs($admin)
        ->get("http://admin.roles.test/admin/roles?brand={$brand->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/roles/Index')
            ->where('selectedBrandId', $brand->id)
            ->has('roles', 1)
            ->where('roles.0.name', 'Event Manager')
            ->where('roles.0.members.0', $member->id));
});

it('assigns a brand role to a user referenced by ULID', function (): void {
    [, $admin] = rolesOperator();

    $brand = $this->withBrand();
    $role = brandRole($brand->id);
    $member = User::factory()->create();

    $this->actingAs($admin)
        ->from("http://admin.roles.test/admin/roles?brand={$brand->id}")
        ->post('http://admin.roles.test/admin/roles/assign', [
            'role_id' => $role->id,
            'user_id' => $member->id,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('model_has_roles', [
        'role_id' => $role->id,
        'model_id' => $member->id,
        'brand_id' => $brand->id,
    ]);

    // The grant is real: under the brand's team context the user holds it.
    app(PermissionRegistrar::class)->setPermissionsTeamId($brand->id);
    expect($member->fresh()->hasRole('Event Manager'))->toBeTrue();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('revokes a brand role from a user', function (): void {
    [, $admin] = rolesOperator();

    $brand = $this->withBrand();
    $role = brandRole($brand->id);
    $member = User::factory()->create();

    DB::table('model_has_roles')->insert([
        'role_id' => $role->id,
        'model_type' => $member->getMorphClass(),
        'model_id' => $member->id,
        'brand_id' => $brand->id,
    ]);

    $this->actingAs($admin)
        ->post('http://admin.roles.test/admin/roles/revoke', [
            'role_id' => $role->id,
            'user_id' => $member->id,
        ])
        ->assertRedirect();

    $this->assertDatabaseMissing('model_has_roles', [
        'role_id' => $role->id,
        'model_id' => $member->id,
    ]);
});

it('403s role assignment without the manage-roles capability', function (): void {
    [, $admin] = rolesOperator(['platform.access-admin']);

    $brand = $this->withBrand();
    $role = brandRole($brand->id);

    $this->actingAs($admin)
        ->post('http://admin.roles.test/admin/roles/assign', [
            'role_id' => $role->id,
            'user_id' => User::factory()->create()->id,
        ])
        ->assertForbidden();
});

it('defaults the brand to the console selection when the page carries none', function (): void {
    [, $admin] = rolesOperator();

    // Named so the alphabetical fallback is deterministic: the suite's own
    // brand would otherwise sort at random against the selected one.
    $this->withBrand()->forceFill(['name' => 'Aardvark Brand'])->save();
    $selected = Brand::factory()->create(['name' => 'Zeta Brand']);

    $this->actingAs($admin)
        ->get('http://admin.roles.test/admin/roles')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('selectedBrandId', Brand::query()->orderBy('name')->value('id')));

    $this->actingAs($admin)
        ->put('http://admin.roles.test/admin/selection', ['brandId' => $selected->getKey(), 'eventId' => null])
        ->assertRedirect();

    $this->actingAs($admin)
        ->get('http://admin.roles.test/admin/roles')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('selectedBrandId', $selected->getKey()));

    // The page's own select still wins — it is a required choice, not a filter.
    $this->actingAs($admin)
        ->get('http://admin.roles.test/admin/roles?brand='.Brand::query()->orderBy('name')->value('id'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('selectedBrandId', Brand::query()->orderBy('name')->value('id')));
});

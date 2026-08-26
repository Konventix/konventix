<?php

declare(strict_types=1);

use Konventix\Platform\Actions\CreateBrand;
use Konventix\Platform\Actions\MaterializeDefaultRoles;
use Konventix\Platform\Actions\SaveRole;
use Konventix\Platform\Actions\UpdateBrandModules;
use Konventix\Platform\Data\BrandData;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Permissions\DefaultRole;
use Konventix\Platform\Permissions\PermissionCatalog;
use Konventix\Registration\Enums\Permission as RegistrationPermission;

/**
 * ADR-0045: a module declares its default roles; materialization creates them
 * per brand as ordinary rows and never rewrites one the operator has touched.
 */
function defaultRoleOf(Brand $brand, string $key): ?Role
{
    return Role::query()->where('brand_id', $brand->getKey())->where('default_key', $key)->first();
}

it('refuses a default whose key does not carry its module prefix', function (): void {
    expect(fn () => DefaultRole::of('desk', 'Registration Desk', RegistrationPermission::ViewRegistrations))
        ->toThrow(InvalidArgumentException::class);
});

it('discovers every module\'s default roles keyed by module', function (): void {
    $sets = app(PermissionCatalog::class)->defaultRoles();

    expect($sets)->toHaveKey('platform')
        ->and($sets['platform'][0]->key)->toBe('platform.brand-admin')
        ->and($sets['platform'][0]->everything)->toBeTrue();
});

it('materializes Brand Admin with every capability in the catalogue', function (): void {
    $brand = Brand::factory()->create(['slug' => 'dr-admin']);

    $report = app(MaterializeDefaultRoles::class)->handle($brand);

    $role = defaultRoleOf($brand, 'platform.brand-admin');

    expect($report->created)->toContain('Brand Admin')
        ->and($role)->not->toBeNull()
        ->and($role->permissions->pluck('name')->sort()->values()->all())
        ->toBe(collect(app(PermissionCatalog::class)->all())->sort()->values()->all())
        ->and($role->customized_at)->toBeNull();
});

it('is idempotent and re-grants a capability an untouched default lost', function (): void {
    $brand = Brand::factory()->create(['slug' => 'dr-idem']);
    $action = app(MaterializeDefaultRoles::class);

    $action->handle($brand);
    $role = defaultRoleOf($brand, 'platform.brand-admin');
    $role->revokePermissionTo('platform.manage-roles');
    expect($role->refresh()->hasPermissionTo('platform.manage-roles'))->toBeFalse();

    $report = $action->handle($brand);

    expect($report->created)->toBe([])
        ->and($report->updated)->toContain('Brand Admin')
        ->and($role->refresh()->hasPermissionTo('platform.manage-roles'))->toBeTrue()
        ->and(Role::query()->where('brand_id', $brand->getKey())->where('default_key', 'platform.brand-admin')->count())->toBe(1);
});

it('leaves a role the operator customized alone, grants and name', function (): void {
    $brand = Brand::factory()->create(['slug' => 'dr-custom']);
    $action = app(MaterializeDefaultRoles::class);

    $action->handle($brand);
    $role = defaultRoleOf($brand, 'platform.brand-admin');

    app(SaveRole::class)->update($role, 'Our Admins', ['platform.view-brands']);

    $report = $action->handle($brand);

    $role->refresh();
    expect($role->customized_at)->not->toBeNull()
        ->and($role->name)->toBe('Our Admins')
        ->and($role->permissions->pluck('name')->all())->toBe(['platform.view-brands'])
        ->and($report->skippedCustomized)->toContain('Our Admins');
});

it('does not hijack an operator-made role that happens to carry the default name', function (): void {
    $brand = Brand::factory()->create(['slug' => 'dr-collide']);
    Role::query()->create(['name' => 'Brand Admin', 'guard_name' => 'web', 'brand_id' => $brand->getKey()]);

    $report = app(MaterializeDefaultRoles::class)->handle($brand);

    expect($report->skippedCollision)->toContain('Brand Admin')
        ->and(Role::query()->where('brand_id', $brand->getKey())->where('name', 'Brand Admin')->count())->toBe(1)
        ->and(defaultRoleOf($brand, 'platform.brand-admin'))->toBeNull();
});

it('materializes only the named module when asked for one', function (): void {
    $brand = Brand::factory()->create(['slug' => 'dr-one']);

    app(MaterializeDefaultRoles::class)->handle($brand, 'registration');

    expect(defaultRoleOf($brand, 'platform.brand-admin'))->toBeNull()
        ->and(defaultRoleOf($brand, 'registration.desk'))->not->toBeNull();
});

it('materializes a gateable module\'s set when that module is activated, and nothing else', function (): void {
    $brand = Brand::factory()->create(['slug' => 'dr-activate']);

    app(UpdateBrandModules::class)->handle($brand, ['competition' => true]);

    expect(defaultRoleOf($brand, 'competition.tournament-admin'))->not->toBeNull()
        ->and(defaultRoleOf($brand, 'platform.brand-admin'))->toBeNull();
});

it('gives a freshly created brand the always-on modules\' sets', function (): void {
    $operator = Operator::factory()->create();

    $brand = app(CreateBrand::class)->handle($operator, new BrandData(
        name: 'Fresh',
        slug: 'dr-fresh',
        hosts: ['fresh.test'],
    ));

    expect(defaultRoleOf($brand, 'platform.brand-admin'))->not->toBeNull()
        ->and(defaultRoleOf($brand, 'registration.desk'))->not->toBeNull()
        ->and(defaultRoleOf($brand, 'competition.tournament-admin'))->toBeNull();
});

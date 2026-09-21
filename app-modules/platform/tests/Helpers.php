<?php

declare(strict_types=1);

use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Helpers that more than one file of this module's suite calls. Pest loads
 * every module's tests/Helpers.php from tests/Pest.php, so they exist in
 * every worker of a parallel run — a function declared inside a *Test.php
 * file exists only in the process that happened to load that file, and the
 * names stay process-global across all modules (.ai/rules/tests.md).
 */
function operatorWithAdmin(): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.op.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach (['platform.access-admin', 'platform.view-brands', 'platform.manage-brands'] as $name) {
        PermissionModel::query()->firstOrCreate([
            'name' => $name,
            'guard_name' => 'web',
        ]);
    }

    $role = Role::query()->create([
        'name' => 'Operator Admin',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo(PermissionModel::query()->pluck('name')->all());

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin];
}

/**
 * The theme editor's operator (ThemeAdminTest, ThemeImportExportTest):
 * mirrors operatorWithAdmin() under its own admin host and brand, and
 * grants exactly the capabilities each test names so a 403 test proves
 * platform.access-admin alone is not enough.
 *
 * @param  array<int, string>  $capabilities
 * @return array{0: Operator, 1: User, 2: Brand}
 */
function operatorWithThemeAdmin(array $capabilities = ['platform.access-admin', 'platform.manage-brand-theme']): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.theme.test'])->save();

    $brand = Brand::factory()->withDomain('theme-brand.test')->create();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($capabilities as $name) {
        PermissionModel::query()->firstOrCreate([
            'name' => $name,
            'guard_name' => 'web',
        ]);
    }

    $role = Role::query()->create([
        'name' => 'Theme Tester',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($capabilities);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin, $brand];
}

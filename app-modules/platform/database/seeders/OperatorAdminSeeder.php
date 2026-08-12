<?php

declare(strict_types=1);

namespace Konventix\Platform\Database\Seeders;

use Illuminate\Database\Seeder;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The operator's admin console prerequisites (ADR-0029): the admin host and
 * the "Operator Admin" role scoped to the operator's ULID (the spatie team
 * dimension is a scope id) with every synced capability granted.
 *
 * Run `permissions:sync` first — this seeder grants whatever rows exist and
 * does not invent any (ADR-0013: permission rows are derived state). The dev
 * login holding the role is created by the HOST DatabaseSeeder: a Platform
 * seeder must not import Identity's User (ADR-0004; the architecture test
 * enforces it for seeders too).
 */
class OperatorAdminSeeder extends Seeder
{
    public function run(): void
    {
        $operator = Operator::query()->firstOrCreate(
            ['name' => 'Konventix Dev Operator'],
            ['legal_name' => 'Konventix Dev Operator', 'contact_email' => 'operator@example.com'],
        );

        if ($operator->admin_host === null) {
            $operator->forceFill(['admin_host' => 'admin.localhost'])->save();
        }

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($operator->getKey());

        /** @var Role $role */
        $role = Role::query()->firstOrCreate([
            'name' => 'Operator Admin',
            'guard_name' => 'web',
            'brand_id' => $operator->getKey(),
        ]);

        $role->syncPermissions(Permission::query()->where('guard_name', 'web')->get());

        $registrar->setPermissionsTeamId(null);
    }
}

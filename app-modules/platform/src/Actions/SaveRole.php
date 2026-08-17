<?php

declare(strict_types=1);

namespace Konventix\Platform\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Platform\Models\Permission;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Permissions\PermissionCatalog;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates and edits per-brand roles with its capability grants (ADR-0013:
 * capabilities are enums, roles are data). Grants are validated against
 * the discovered catalogue — a name no module declares cannot be granted,
 * and granting a declared one upserts its row exactly as
 * `permissions:sync` would.
 */
final class SaveRole
{
    public function __construct(private readonly PermissionCatalog $catalog) {}

    /**
     * @param  list<string>  $permissions
     */
    public function create(string $brandId, string $name, array $permissions): Role
    {
        return DB::transaction(function () use ($brandId, $name, $permissions): Role {
            $this->guardName($brandId, $name, null);
            $granted = $this->guardPermissions($permissions);

            $role = Role::query()->create([
                'name' => $name,
                'guard_name' => 'web',
                'brand_id' => $brandId,
            ]);

            $role->syncPermissions($granted);

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $role;
        });
    }

    private function guardName(string $brandId, string $name, ?string $ignoreRoleId): void
    {
        $exists = Role::query()
            ->where('brand_id', $brandId)
            ->where('name', $name)
            ->when($ignoreRoleId !== null, fn ($query) => $query->whereKeyNot($ignoreRoleId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => 'This brand already has a role with that name.']);
        }
    }

    /**
     * @param  list<string>  $permissions
     * @return list<Permission>
     */
    private function guardPermissions(array $permissions): array
    {
        $declared = $this->catalog->all();
        $unknown = array_diff($permissions, $declared);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'permissions' => 'Unknown capabilities: '.implode(', ', $unknown),
            ]);
        }

        return array_map(
            fn (string $name): Permission => Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]),
            $permissions,
        );
    }

    /**
     * Renames and re-grants under the same guards.
     *
     * @param  list<string>  $permissions
     */
    public function update(Role $role, string $name, array $permissions): Role
    {
        return DB::transaction(function () use ($role, $name, $permissions): Role {
            $this->guardName((string) $role->getAttribute('brand_id'), $name, $role->getKey());
            $granted = $this->guardPermissions($permissions);

            $role->update(['name' => $name]);
            $role->syncPermissions($granted);

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $role;
        });
    }
}

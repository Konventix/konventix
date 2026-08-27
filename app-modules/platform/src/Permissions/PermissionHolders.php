<?php

declare(strict_types=1);

namespace Konventix\Platform\Permissions;

use Illuminate\Support\Facades\DB;

/**
 * Who, on one brand, holds one permission — a pure query builder over
 * spatie/laravel-permission's own pivot tables (ADR-0013's team-scoped
 * roles), never through Eloquent `Role`/`Permission` models and never
 * through Identity's `User` (ADR-0004): a module that needs to notify
 * "whoever can do X" (Commerce's shop operators, ADR-0048 point 3) has no
 * user model of its own to filter by, so this reads
 * `config('auth.providers.users.model')` as a plain string compared
 * against `model_has_roles.model_type` instead of importing it.
 *
 * Table and column names come from `config('permission.table_names')` /
 * `column_names()` rather than being hardcoded — `team_foreign_key` is
 * `brand_id` in this project (`config/permission.php`), but the query
 * reads the config rather than assuming it.
 */
final class PermissionHolders
{
    /**
     * @return list<string>
     */
    public function userIdsWithPermission(string $brandId, string $permission): array
    {
        $tables = config('permission.table_names');
        $columns = config('permission.column_names');
        $teamKey = $columns['team_foreign_key'] ?? 'brand_id';
        $modelKey = $columns['model_morph_key'] ?? 'model_id';
        $userClass = (string) config('auth.providers.users.model');

        $rolesTable = $tables['roles'];
        $modelHasRolesTable = $tables['model_has_roles'];
        $roleHasPermissionsTable = $tables['role_has_permissions'];
        $permissionsTable = $tables['permissions'];

        $ids = DB::table($modelHasRolesTable)
            ->join($rolesTable, "{$rolesTable}.id", '=', "{$modelHasRolesTable}.role_id")
            ->join($roleHasPermissionsTable, "{$roleHasPermissionsTable}.role_id", '=', "{$rolesTable}.id")
            ->join($permissionsTable, "{$permissionsTable}.id", '=', "{$roleHasPermissionsTable}.permission_id")
            ->where("{$rolesTable}.{$teamKey}", $brandId)
            ->where("{$permissionsTable}.name", $permission)
            ->where("{$modelHasRolesTable}.model_type", $userClass)
            ->distinct()
            ->pluck("{$modelHasRolesTable}.{$modelKey}");

        return array_values($ids->map(fn (mixed $id): string => (string) $id)->all());
    }
}

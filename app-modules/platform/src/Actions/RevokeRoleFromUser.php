<?php

declare(strict_types=1);

namespace Konventix\Platform\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Revokes a brand role from a user referenced by ULID — the inverse of
 * `AssignRoleToUser`, same boundary reasoning (D-BATCH, ADR-0004).
 */
final class RevokeRoleFromUser
{
    public function __construct(
        private readonly PermissionRegistrar $registrar,
    ) {}

    public function handle(Role $role, string $userId): void
    {
        DB::transaction(function () use ($role, $userId): void {
            $teamKey = (string) config('permission.column_names.team_foreign_key');

            $role->users()
                ->wherePivot($teamKey, $role->getAttribute($teamKey))
                ->detach($userId);
        });

        $this->registrar->forgetCachedPermissions();
    }
}

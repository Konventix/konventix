<?php

declare(strict_types=1);

namespace Konventix\Platform\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Grants a brand role to a user referenced by ULID (decision log Round 6,
 * D-BATCH: Platform owns the role-assignment screen; no Identity model
 * import). The pivot is written through spatie's own `Role::users()`
 * morph relation with a raw key — the model class behind it comes from the
 * auth guard's provider config, so no `use Konventix\Identity\...` exists
 * anywhere in this module (ADR-0004).
 *
 * The `brand_id` team column on the pivot is set explicitly from the
 * role's own brand (ADR-0013: teams are bound to brand_id) rather than
 * relying on ambient registrar state — assignment happens on the operator
 * host, where no brand is resolved.
 */
final class AssignRoleToUser
{
    public function __construct(
        private readonly PermissionRegistrar $registrar,
    ) {}

    public function handle(Role $role, string $userId): void
    {
        DB::transaction(function () use ($role, $userId): void {
            $teamKey = (string) config('permission.column_names.team_foreign_key');

            $alreadyAssigned = $role->users()
                ->wherePivot($teamKey, $role->getAttribute($teamKey))
                ->whereKey($userId)
                ->exists();

            if ($alreadyAssigned) {
                return;
            }

            $role->users()->attach($userId, [
                $teamKey => $role->getAttribute($teamKey),
            ]);
        });

        $this->registrar->forgetCachedPermissions();
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Platform\Actions\AssignRoleToUser;
use Konventix\Platform\Actions\RevokeRoleFromUser;
use Konventix\Platform\Enums\Permission;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Role;

/**
 * Operator console: per-brand role assignment (decision log Round 6,
 * D-BATCH — Platform owns this screen outright; users appear as ULIDs,
 * never through an Identity model import, ADR-0004/ADR-0013).
 *
 * Member display data (name/email) is deliberately absent: a
 * Platform-maintained identity read model fed by Identity's events is the
 * sanctioned way to add it later — see TODO.md.
 */
class RoleAssignmentController
{
    public function index(Request $request): Response
    {
        Gate::authorize(Permission::ManageRoles->value);

        $brands = Brand::query()->orderBy('name')->get(['id', 'name']);

        $brandId = $request->string('brand')->toString() ?: $brands->first()?->getKey();

        $teamKey = (string) config('permission.column_names.team_foreign_key');

        $roles = $brandId === null ? collect() : Role::query()
            ->where($teamKey, $brandId)
            ->orderBy('name')
            ->get()
            ->map(function (Role $role) use ($teamKey): array {
                $members = DB::table(config('permission.table_names.model_has_roles'))
                    ->where('role_id', $role->getKey())
                    ->where($teamKey, $role->getAttribute($teamKey))
                    ->pluck('model_id');

                return [
                    'id' => $role->getKey(),
                    'name' => $role->name,
                    'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
                    'members' => $members->all(),
                ];
            });

        return Inertia::render('admin/roles/Index', [
            'brands' => $brands->map(fn (Brand $brand): array => [
                'id' => $brand->getKey(),
                'name' => $brand->name,
            ])->all(),
            'selectedBrandId' => $brandId,
            'roles' => $roles->all(),
        ]);
    }

    public function assign(Request $request, AssignRoleToUser $assignRoleToUser): RedirectResponse
    {
        Gate::authorize(Permission::ManageRoles->value);

        $validated = $request->validate([
            'role_id' => ['required', 'ulid', 'exists:roles,id'],
            'user_id' => ['required', 'ulid', 'exists:users,id'],
        ]);

        /** @var Role $role */
        $role = Role::query()->findOrFail($validated['role_id']);

        $assignRoleToUser->handle($role, $validated['user_id']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role assigned.')]);

        return back();
    }

    public function revoke(Request $request, RevokeRoleFromUser $revokeRoleFromUser): RedirectResponse
    {
        Gate::authorize(Permission::ManageRoles->value);

        $validated = $request->validate([
            'role_id' => ['required', 'ulid', 'exists:roles,id'],
            'user_id' => ['required', 'ulid'],
        ]);

        /** @var Role $role */
        $role = Role::query()->findOrFail($validated['role_id']);

        $revokeRoleFromUser->handle($role, $validated['user_id']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role revoked.')]);

        return back();
    }
}

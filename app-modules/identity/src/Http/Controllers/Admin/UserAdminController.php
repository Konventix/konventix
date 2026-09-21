<?php

declare(strict_types=1);

namespace Konventix\Identity\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Identity\Actions\LockUserAccount;
use Konventix\Identity\Actions\MergeUserAccounts;
use Konventix\Identity\Actions\UnlockUserAccount;
use Konventix\Identity\Actions\UpdateUserAccount;
use Konventix\Identity\Data\AdminUpdateUserData;
use Konventix\Identity\Data\MergeUserAccountData;
use Konventix\Identity\Enums\Permission;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Operators\ConsoleSelection;

/**
 * Operator console: user administration (ADR-0029). Accounts are
 * operator-global (ADR-0016), so this list legitimately spans brands — the
 * membership lookup states that with the explicit withoutBrandScope() escape
 * hatch (ADR-0010).
 */
class UserAdminController
{
    use HasDataTableQuery;

    public function index(Request $request, ConsoleSelection $selection): Response
    {
        Gate::authorize(Permission::ViewUsers->value);

        $perPage = $this->resolvePerPage($request);
        $sort = $this->resolveSort($request, allowedSorts: ['name', 'email', 'created_at'], default: 'name');

        // Accounts are operator-global (ADR-0016), so the console's pick
        // narrows this list through brand membership rather than through the
        // account itself. A default, cleared per list with `brand=all`.
        $brand = $this->resolveSelectionFilter($request, 'brand', $selection->brandId());

        $users = User::query()
            ->when($brand !== null, fn ($query) => $query->whereIn(
                'id',
                BrandMembership::withoutBrandScope()->forBrand($brand)->select('user_id'),
            ))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->trim().'%';

                $query->where(function ($inner) use ($term): void {
                    $inner->where('name', 'ilike', $term)
                        ->orWhere('email', 'ilike', $term);
                });
            })
            ->orderBy($sort['column'], $sort['direction'])
            ->paginate($perPage)
            ->withQueryString();

        $membershipCounts = BrandMembership::withoutBrandScope()
            ->whereIn('user_id', $users->getCollection()->pluck('id'))
            ->selectRaw('user_id, count(*) as brand_count')
            ->groupBy('user_id')
            ->pluck('brand_count', 'user_id');

        return Inertia::render('admin/users/Index', [
            'users' => $users->through(fn (User $user): array => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'state' => $user->state->value,
                'verified' => $user->email_verified_at !== null,
                'brandCount' => (int) ($membershipCounts[$user->getKey()] ?? 0),
            ]),
            'brands' => $this->brandOptions(),
            'filters' => $this->currentFilters($request, $sort, $perPage, ['brand' => $brand]),
        ]);
    }

    public function edit(User $user): Response
    {
        Gate::authorize(Permission::ViewUsers->value);

        $memberships = BrandMembership::withoutBrandScope()
            ->where('user_id', $user->getKey())
            ->get();

        $brandNames = Brand::query()
            ->whereIn('id', $memberships->pluck('brand_id'))
            ->pluck('name', 'id');

        // Role names across all brands, read directly off the pivot: the
        // spatie relation is scoped to one team at a time, and this page
        // deliberately shows the whole account (ADR-0016).
        $roles = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $user->getKey())
            ->where('model_has_roles.model_type', $user->getMorphClass())
            ->get(['roles.name', 'roles.brand_id']);

        $roleBrandNames = Brand::query()
            ->whereIn('id', $roles->pluck('brand_id'))
            ->pluck('name', 'id');

        // #81: the two merge-adjacent facts the edit page needs — whether
        // this account was itself closed into another one, and which
        // accounts this one has absorbed. Both are plain queries against
        // Identity's own table, not a relation on `User` (its class docblock
        // asks that every such addition be justified, and a one-off lookup
        // used in exactly one place does not earn a permanent method).
        $mergedInto = $user->merged_into_user_id === null
            ? null
            : User::query()->find($user->merged_into_user_id, ['id', 'email']);

        $mergedAccounts = User::query()
            ->where('merged_into_user_id', $user->getKey())
            ->orderByDesc('updated_at')
            ->get(['id', 'email', 'updated_at']);

        return Inertia::render('admin/users/Edit', [
            'user' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
                'timezone' => $user->timezone,
                'state' => $user->state->value,
                'verified' => $user->email_verified_at !== null,
                'twoFactor' => $user->two_factor_confirmed_at !== null,
                'createdAt' => $user->created_at?->toDateString(),
                'mergedInto' => $mergedInto === null ? null : [
                    'id' => $mergedInto->getKey(),
                    'email' => $mergedInto->email,
                ],
            ],
            'mergedAccounts' => $mergedAccounts
                ->map(fn (User $merged): array => [
                    'id' => $merged->getKey(),
                    'email' => $merged->email,
                    // Best available marker: merging is the last write a
                    // closed account's row gets under ordinary operation.
                    'mergedAt' => $merged->updated_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'memberships' => $memberships
                ->map(fn (BrandMembership $membership): array => [
                    'brand' => $brandNames[$membership->brand_id] ?? $membership->brand_id,
                    'firstSeenAt' => $membership->first_seen_at->toDateString(),
                ])
                ->values()
                ->all(),
            'roles' => $roles
                ->map(fn (object $role): array => [
                    'name' => $role->name,
                    'brand' => $roleBrandNames[$role->brand_id] ?? $role->brand_id,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function update(User $user, AdminUpdateUserData $data, UpdateUserAccount $action): RedirectResponse
    {
        Gate::authorize(Permission::ManageUsers->value);

        $action->handle($user, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account saved.')]);

        return to_route('admin.users.edit', $user);
    }

    public function lock(User $user, Request $request, LockUserAccount $action): RedirectResponse
    {
        Gate::authorize(Permission::LockUser->value);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $action->handle($user, $data['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account locked.')]);

        return to_route('admin.users.edit', $user);
    }

    public function unlock(User $user, UnlockUserAccount $action): RedirectResponse
    {
        Gate::authorize(Permission::UnlockUser->value);

        $action->handle($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account unlocked.')]);

        return to_route('admin.users.edit', $user);
    }

    /**
     * #81: `$user` is the account that survives — see `MergeUserAccounts`'s
     * docblock for why the direction is "whichever account this page is
     * open on" rather than something this method infers. `ManageUsers` is
     * the same capability `update()`/`lock()` already gate; merging two
     * accounts is account administration, not a new capability of its own.
     */
    public function merge(User $user, MergeUserAccountData $data, MergeUserAccounts $action, Request $request): RedirectResponse
    {
        Gate::authorize(Permission::ManageUsers->value);

        /** @var User $operator */
        $operator = $request->user();

        $action->handle(
            $user,
            $data->email,
            $operator,
            acknowledgedClosedAccountHistoryStaysBehind: $data->acknowledgedClosedAccountHistoryStaysBehind,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Accounts merged.')]);

        return to_route('admin.users.edit', $user);
    }
}

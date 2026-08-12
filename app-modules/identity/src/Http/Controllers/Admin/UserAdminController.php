<?php

declare(strict_types=1);

namespace Konventix\Identity\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Identity\Enums\Permission;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;

/**
 * Operator console: user administration (ADR-0029). Accounts are
 * operator-global (ADR-0016), so this list legitimately spans brands — the
 * membership lookup states that with the explicit withoutBrandScope() escape
 * hatch (ADR-0010).
 */
class UserAdminController
{
    use HasDataTableQuery;

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::ViewUsers->value);

        $perPage = $this->resolvePerPage($request);
        $sort = $this->resolveSort($request, allowedSorts: ['name', 'email', 'created_at'], default: 'name');

        $users = User::query()
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
            'filters' => $this->currentFilters($request, $sort, $perPage),
        ]);
    }
}

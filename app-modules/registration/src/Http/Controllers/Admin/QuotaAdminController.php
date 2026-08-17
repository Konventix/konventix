<?php

declare(strict_types=1);

namespace Konventix\Registration\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;
use Konventix\Platform\Models\Brand;
use Konventix\Registration\Actions\CreateQuota;
use Konventix\Registration\Actions\UpdateQuota;
use Konventix\Registration\Data\QuotaData;
use Konventix\Registration\Enums\Permission;
use Konventix\Registration\Models\Quota;
use Konventix\Registration\Models\RegistrationType;

/**
 * Operator console: quotas (ADR-0029). A quota is a standalone entity shared
 * by several types (ADR-0005), which is exactly why it gets its own screen
 * rather than a field on the type form.
 *
 * The list shows which types draw on each quota, because "how many places are
 * left" is only answerable together with "for whom".
 */
class QuotaAdminController
{
    use HasDataTableQuery;

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::ManageQuotas->value);

        $perPage = $this->resolvePerPage($request);
        $sort = $this->resolveSort($request, allowedSorts: ['name', 'capacity', 'consumed'], default: 'name');

        $quotas = Quota::withoutBrandScope()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $query->where('name', 'ilike', '%'.$request->string('search')->trim().'%');
            })
            ->orderBy($sort['column'], $sort['direction'])
            ->paginate($perPage)
            ->withQueryString();

        /** @var array<string, string> $brandNames */
        $brandNames = Brand::query()->pluck('name', 'id')->all();

        /** @var list<string> $quotaIds */
        $quotaIds = array_values($quotas->getCollection()->pluck('id')->all());
        $typeNames = $this->typeNamesByQuota($quotaIds);

        return Inertia::render('admin/quotas/Index', [
            'quotas' => $quotas->through(fn (Quota $quota): array => [
                'id' => $quota->getKey(),
                'name' => $quota->name,
                'brandName' => $brandNames[$quota->brand_id] ?? '—',
                'capacity' => $quota->capacity,
                'consumed' => $quota->consumed,
                'remaining' => $quota->remaining(),
                'typeNames' => $typeNames[$quota->getKey()] ?? [],
            ]),
            'brands' => array_map(
                fn (string $name, string $id): array => ['id' => $id, 'name' => $name],
                array_values($brandNames),
                array_keys($brandNames),
            ),
            'filters' => $this->currentFilters($request, $sort, $perPage),
        ]);
    }

    public function store(Request $request, CreateQuota $createQuota): RedirectResponse
    {
        Gate::authorize(Permission::ManageQuotas->value);

        $brandId = $request->validate(['brand_id' => ['required', 'ulid', 'exists:brands,id']])['brand_id'];

        $createQuota->handle($brandId, QuotaData::validateAndCreate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Quota created.')]);

        return back();
    }

    public function update(Request $request, string $quota, UpdateQuota $updateQuota): RedirectResponse
    {
        Gate::authorize(Permission::ManageQuotas->value);

        /** @var Quota $model */
        $model = Quota::withoutBrandScope()->findOrFail($quota);

        $updateQuota->handle($model, QuotaData::validateAndCreate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Quota saved.')]);

        return back();
    }

    /**
     * Which types draw on each quota, in one query for the whole page — a
     * per-row relation load would both N+1 and re-apply `BrandScope` on the
     * related model, which reads zero rows on the admin host (.ai/rules).
     *
     * @param  list<string>  $quotaIds
     * @return array<string, list<string>>
     */
    private function typeNamesByQuota(array $quotaIds): array
    {
        if ($quotaIds === []) {
            return [];
        }

        return RegistrationType::withoutBrandScope()
            ->join('registration_type_quota as link', 'link.registration_type_id', '=', 'registration_types.id')
            ->whereIn('link.quota_id', $quotaIds)
            ->orderBy('registration_types.name')
            ->get(['registration_types.name', 'link.quota_id'])
            ->groupBy('quota_id')
            ->map(fn ($rows): array => array_values($rows->pluck('name')->all()))
            ->all();
    }
}

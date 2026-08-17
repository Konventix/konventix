<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Platform\Actions\CreateBrand;
use Konventix\Platform\Actions\UpdateBrand;
use Konventix\Platform\Actions\UpdateBrandModules;
use Konventix\Platform\Data\BrandData;
use Konventix\Platform\Data\UpdateBrandData;
use Konventix\Platform\Enums\Permission;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Modules\ModuleGate;
use Konventix\Platform\Operators\OperatorContext;
use Laravel\Pennant\Feature;

/**
 * Operator console: brand management (ADR-0029). Route middleware already
 * requires the admin host, auth and platform.access-admin; per-operation
 * capabilities are checked here.
 */
class BrandAdminController
{
    use HasDataTableQuery;

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::ViewBrands->value);

        $perPage = $this->resolvePerPage($request);
        $sort = $this->resolveSort($request, allowedSorts: ['name', 'slug', 'created_at'], default: 'name');

        $brands = Brand::query()
            ->with('domains')
            ->withCount('domains')
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->trim().'%';

                $query->where(function ($inner) use ($term): void {
                    $inner->where('name', 'ilike', $term)
                        ->orWhere('slug', 'ilike', $term);
                });
            })
            ->orderBy($sort['column'], $sort['direction'])
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('admin/brands/Index', [
            'brands' => $brands->through(fn (Brand $brand): array => [
                'id' => $brand->getKey(),
                'name' => $brand->name,
                'slug' => $brand->slug,
                'hosts' => $brand->domains->pluck('host')->all(),
            ]),
            'filters' => $this->currentFilters($request, $sort, $perPage),
        ]);
    }

    public function store(BrandData $data, CreateBrand $createBrand, OperatorContext $context): RedirectResponse
    {
        Gate::authorize(Permission::ManageBrands->value);

        $createBrand->handle($context->current() ?? abort(404), $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Brand created.')]);

        return to_route('admin.brands.index');
    }

    public function edit(Brand $brand, ModuleGate $gate): Response
    {
        Gate::authorize(Permission::ViewBrands->value);

        $gateable = collect($gate->gateableModuleNames())->sort()->values();

        return Inertia::render('admin/brands/Edit', [
            'brand' => [
                'id' => $brand->getKey(),
                'name' => $brand->name,
                'slug' => $brand->slug,
                'currency' => $brand->currency,
                'hosts' => $brand->domains()->orderByDesc('is_primary')->pluck('host')->all(),
            ],
            // Per-brand module activation (ADR-0003): gateable modules with
            // their Pennant state for THIS brand; everything else is always
            // on and only listed so the page tells the whole story.
            'modules' => $gateable
                ->map(fn (string $module): array => [
                    'module' => $module,
                    'active' => Feature::for($brand)->active($gate->featureName($module)),
                ])
                ->all(),
            'alwaysOn' => collect($gate->allModuleNames())
                ->reject(fn (string $module): bool => $gateable->contains($module))
                ->sort()
                ->values()
                ->all(),
        ]);
    }

    public function update(Brand $brand, UpdateBrandData $data, UpdateBrand $updateBrand): RedirectResponse
    {
        Gate::authorize(Permission::ManageBrands->value);

        $updateBrand->handle($brand, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Brand saved.')]);

        return to_route('admin.brands.edit', $brand);
    }

    public function updateModules(Brand $brand, Request $request, UpdateBrandModules $updateModules): RedirectResponse
    {
        Gate::authorize(Permission::ManageModuleActivation->value);

        $data = $request->validate([
            'modules' => ['required', 'array'],
            'modules.*' => ['boolean'],
        ]);

        $updateModules->handle($brand, array_map(
            fn (mixed $active): bool => (bool) $active,
            $data['modules'],
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Module activation saved.')]);

        return to_route('admin.brands.edit', $brand);
    }
}

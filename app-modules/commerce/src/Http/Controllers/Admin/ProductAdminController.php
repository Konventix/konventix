<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Commerce\Actions\DeleteProduct;
use Konventix\Commerce\Actions\SaveProduct;
use Konventix\Commerce\Data\ProductData;
use Konventix\Commerce\Data\ProductRowData;
use Konventix\Commerce\Data\SaveProductData;
use Konventix\Commerce\Enums\Permission;
use Konventix\Commerce\Enums\PriceDisplay;
use Konventix\Commerce\Enums\TaxClass;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\ProductVariant;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Operators\ConsoleEventSources;
use Konventix\Platform\Operators\ConsoleSelection;

/**
 * Operator console: a brand's shop catalogue (ADR-0047, Task 7). Route
 * middleware already requires the admin host, auth and
 * `platform.access-admin`; per-operation capabilities are checked here.
 *
 * Products and variants are brand-owned (ADR-0010); the admin host hardens
 * `BrandScope` to zero rows (ADR-0029), so every query here escapes it
 * deliberately — including the variants relation, which would otherwise
 * re-apply the scope and silently read empty (.ai/rules/app-modules-src.md).
 */
class ProductAdminController
{
    use HasDataTableQuery;

    public function index(Request $request, ConsoleSelection $selection): Response
    {
        Gate::authorize(Permission::ViewProducts->value);

        $perPage = $this->resolvePerPage($request);
        $sort = $this->resolveSort($request, allowedSorts: ['name', 'published_at', 'updated_at'], default: 'name');
        $brandId = $this->resolveSelectionFilter($request, 'brand', $selection->brandId());

        $brandNames = Brand::query()->pluck('name', 'id');

        $products = Product::withoutBrandScope()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->trim().'%';
                $query->where(fn ($inner) => $inner->where('name', 'ilike', $term)->orWhere('slug', 'ilike', $term));
            })
            ->when($brandId !== null, fn ($query) => $query->forBrand($brandId))
            ->orderBy($sort['column'], $sort['direction'])
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('admin/products/Index', [
            'products' => $products->through(fn (Product $product): array => (new ProductRowData(
                id: $product->getKey(),
                name: $product->name,
                slug: $product->slug,
                brandName: $brandNames[$product->brand_id] ?? '—',
                unitAmountNet: $product->unit_amount_net,
                priceDisplay: $product->price_display,
                published: $product->published_at !== null,
                updatedAt: $product->updated_at?->toDateTimeString(),
            ))->toArray()),
            'brands' => $this->brandOptions(),
            'filters' => $this->currentFilters($request, $sort, $perPage, ['brand' => $brandId]),
        ]);
    }

    /**
     * Creation resolves the brand from the console's own selection —
     * `SaveProductData` carries no `brandId` field, so an operator must have
     * one picked in the shell before a "New product" submission can land
     * anywhere.
     */
    public function store(SaveProductData $data, SaveProduct $save, ConsoleSelection $selection): RedirectResponse
    {
        Gate::authorize(Permission::ManageProducts->value);

        $brand = $selection->brandId() === null ? null : Brand::query()->find($selection->brandId());

        if ($brand === null) {
            throw ValidationException::withMessages(['brand' => __('Pick a brand in the console shell first.')]);
        }

        $product = $save->handle($brand, null, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product created.')]);

        return to_route('admin.products.edit', $product->getKey());
    }

    public function edit(string $product, MediaLibrary $library, ConsoleEventSources $events): Response
    {
        Gate::authorize(Permission::ViewProducts->value);

        $model = Product::withoutBrandScope()->findOrFail($product);
        $brand = Brand::query()->findOrFail($model->brand_id);

        $variants = ProductVariant::withoutBrandScope()
            ->where('product_id', $model->getKey())
            ->orderBy('position')
            ->get();

        return Inertia::render('admin/products/Edit', [
            'product' => ProductData::ofModel($model, $brand->name, $variants)->toArray(),
            'taxClasses' => array_map(fn (TaxClass $case): string => $case->value, TaxClass::cases()),
            'priceDisplays' => array_map(fn (PriceDisplay $case): string => $case->value, PriceDisplay::cases()),
            'media' => $library->pickableImages($brand->getKey()),
            'events' => $events->hasAny() ? $events->eventsFor($brand->getKey()) : [],
        ]);
    }

    public function update(string $product, SaveProductData $data, SaveProduct $save): RedirectResponse
    {
        Gate::authorize(Permission::ManageProducts->value);

        $model = Product::withoutBrandScope()->findOrFail($product);
        $brand = Brand::query()->findOrFail($model->brand_id);

        $save->handle($brand, $model, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product saved.')]);

        return to_route('admin.products.edit', $model->getKey());
    }

    public function destroy(string $product, DeleteProduct $delete): RedirectResponse
    {
        Gate::authorize(Permission::ManageProducts->value);

        $delete->handle(Product::withoutBrandScope()->findOrFail($product));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Product deleted.')]);

        return to_route('admin.products.index');
    }
}

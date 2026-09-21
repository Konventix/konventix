<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Platform\Actions\UpdateBrandMenu;
use Konventix\Platform\Data\BrandMenuData;
use Konventix\Platform\Data\BrandMenuItemData;
use Konventix\Platform\Data\BrandMenuPageOptionData;
use Konventix\Platform\Enums\Permission;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\BrandMenuItem;
use Konventix\Platform\Models\StaticPage;
use Konventix\Platform\Operators\ConsoleSelection;
use Konventix\Platform\Operators\OperatorContext;

/**
 * Operator console: a brand's topbar menu (ADR-0029). Route middleware
 * already requires the admin host, auth and `platform.access-admin`; the
 * capability is checked here.
 *
 * Unlike the footer there is no operator-wide list: the menu is a brand's
 * own, so the page is nothing until a brand is picked — on the page, else
 * the console's selection, the same default-not-scope the footer uses.
 */
class BrandMenuAdminController
{
    public function edit(Request $request, OperatorContext $context, ConsoleSelection $selection): Response
    {
        Gate::authorize(Permission::ManageBrandMenu->value);

        $operator = $context->current() ?? abort(404);

        $brands = Brand::query()->where('operator_id', $operator->getKey())->orderBy('name')->get(['id', 'name']);

        $brandId = $request->string('brand')->toString() ?: $selection->brandId();
        $brand = $brandId === null ? null : $brands->firstWhere('id', $brandId);

        return Inertia::render('admin/pages/Menu', [
            'brands' => $brands->map(fn (Brand $candidate): array => [
                'id' => $candidate->getKey(),
                'name' => $candidate->name,
            ])->all(),
            'brand' => $brand === null ? null : ['id' => $brand->getKey(), 'name' => $brand->name],
            'items' => $brand === null ? null : $this->itemsOf($brand->getKey()),
            'pages' => $brand === null ? [] : $this->pagesOf($brand->getKey()),
        ]);
    }

    /**
     * @return array<int, BrandMenuItemData>
     */
    private function itemsOf(string $brandId): array
    {
        return BrandMenuItem::withoutBrandScope()
            ->forBrand($brandId)
            ->inDisplayOrder()
            ->get()
            ->map(fn (BrandMenuItem $item): BrandMenuItemData => BrandMenuItemData::ofModel($item))
            ->all();
    }

    /**
     * @return array<int, BrandMenuPageOptionData>
     */
    private function pagesOf(string $brandId): array
    {
        return StaticPage::withoutBrandScope()
            ->forBrand($brandId)
            ->orderBy('title')
            ->get()
            ->map(fn (StaticPage $page): BrandMenuPageOptionData => BrandMenuPageOptionData::ofModel($page))
            ->all();
    }

    public function update(BrandMenuData $data, UpdateBrandMenu $updateBrandMenu, OperatorContext $context): RedirectResponse
    {
        Gate::authorize(Permission::ManageBrandMenu->value);

        $updateBrandMenu->handle($context->current() ?? abort(404), $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Menu saved.')]);

        // Keep the picked brand, or the form would vanish on the very save
        // that filled it.
        return to_route('admin.menu.edit', ['brand' => $data->brandId]);
    }
}

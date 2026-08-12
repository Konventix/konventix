<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Platform\Actions\UpdateBrandTheme;
use Konventix\Platform\Data\BrandSummaryData;
use Konventix\Platform\Data\BrandThemeData;
use Konventix\Platform\Data\ThemeCatalogData;
use Konventix\Platform\Enums\Permission;
use Konventix\Platform\Models\Brand;

/**
 * Operator console: the brand theme editor (ADR-0008, ADR-0029). Route
 * middleware already requires the admin host, auth and
 * platform.access-admin; the theme-specific capability is checked here.
 */
class ThemeAdminController
{
    public function edit(Brand $brand): Response
    {
        Gate::authorize(Permission::ManageBrandTheme->value);

        return Inertia::render('admin/themes/Edit', [
            'brand' => BrandSummaryData::fromModel($brand),
            'tokens' => $brand->themeTokens(),
            'catalog' => ThemeCatalogData::fromCatalog(),
        ]);
    }

    public function update(Brand $brand, BrandThemeData $data, UpdateBrandTheme $updateBrandTheme): RedirectResponse
    {
        Gate::authorize(Permission::ManageBrandTheme->value);

        $updateBrandTheme->handle($brand, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Theme updated.')]);

        return to_route('admin.brands.theme.edit', $brand);
    }
}

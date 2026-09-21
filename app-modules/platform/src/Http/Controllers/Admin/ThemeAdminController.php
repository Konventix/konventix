<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use JsonException;
use Konventix\Platform\Actions\ExportBrandTheme;
use Konventix\Platform\Actions\UpdateBrandTheme;
use Konventix\Platform\Data\BrandSummaryData;
use Konventix\Platform\Data\BrandThemeData;
use Konventix\Platform\Data\BrandThemeExportData;
use Konventix\Platform\Data\ThemeCatalogData;
use Konventix\Platform\Enums\Permission;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Theming\ThemeCanonicalJson;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            'darkTokens' => $brand->darkThemeTokens(),
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

    /**
     * The theme as a `konventix.brand-theme/v1` file: canonical JSON, so two
     * exports diff cleanly and an import of an export changes nothing.
     */
    public function export(Brand $brand, ExportBrandTheme $exportBrandTheme): StreamedResponse
    {
        Gate::authorize(Permission::ManageBrandTheme->value);

        $json = $exportBrandTheme->handle($brand);

        return response()->streamDownload(
            function () use ($json): void {
                echo $json;
            },
            $brand->slug.'-theme.json',
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * An uploaded export replaces both token maps. The file is read and
     * decoded here; its envelope is `BrandThemeExportData`'s to validate and
     * the maps go through `BrandThemeData` and `UpdateBrandTheme`, so an
     * unknown token name, a bad colour or a failing contrast pair is refused
     * with the same 422 a save gets — and the stored theme stays as it was.
     */
    public function import(Request $request, Brand $brand, UpdateBrandTheme $updateBrandTheme): RedirectResponse
    {
        Gate::authorize(Permission::ManageBrandTheme->value);

        $validated = $request->validate([
            // A theme is two small maps; 256 KB leaves room for any of them.
            // `mimes` is not used because a JSON body is guessed as text by
            // the sniffer and would be refused for the wrong reason.
            'file' => ['required', 'file', 'max:256'],
        ]);

        try {
            $document = ThemeCanonicalJson::decode((string) $validated['file']->get());
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'file' => __('The file is not a JSON theme export.'),
            ]);
        }

        $theme = BrandThemeExportData::validateAndCreate($document)->toThemeData();

        $updateBrandTheme->handle($brand, $theme);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Theme imported.')]);

        return to_route('admin.brands.theme.edit', $brand);
    }
}

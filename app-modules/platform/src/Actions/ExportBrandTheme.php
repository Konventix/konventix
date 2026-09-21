<?php

declare(strict_types=1);

namespace Konventix\Platform\Actions;

use Konventix\Platform\Data\BrandThemeExportData;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Theming\ThemeCanonicalJson;

/**
 * A brand's theme as canonical JSON (ADR-0008) — the console download hands
 * out exactly this string. Nothing is derived here: the two token maps the
 * brand stores are the export, wrapped in the `konventix.brand-theme/v1`
 * envelope.
 */
final class ExportBrandTheme
{
    public function handle(Brand $brand): string
    {
        return ThemeCanonicalJson::encode(BrandThemeExportData::ofBrand($brand));
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Platform\Actions;

use Konventix\Platform\Data\BrandThemeExportData;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Theming\ThemeCanonicalJson;

/**
 * A brand's theme as canonical JSON (ADR-0008) — the console download hands
 * out exactly this string.
 *
 * The document is `BrandThemeExportData`'s to build, and it is more than the
 * brand's stored overrides: every catalog token at its effective value in
 * both schemes, the names of the ones the brand owns, and the reference block
 * an author needs. A brand that has themed nothing downloads the complete
 * default theme rather than an empty pair of maps.
 */
final class ExportBrandTheme
{
    public function handle(Brand $brand): string
    {
        return ThemeCanonicalJson::encode(BrandThemeExportData::ofBrand($brand));
    }
}

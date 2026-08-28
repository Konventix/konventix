<?php

declare(strict_types=1);

namespace Konventix\Messaging\Actions;

use Konventix\Messaging\Models\TemplateOverride;
use Konventix\Platform\Models\Brand;

/**
 * Deletes a brand's override for one template key and locale (Task 8,
 * ADR-0048 decision 4) — `TemplateRenderer` falls back to the
 * module-registered default the moment the row is gone. Deleting a row that
 * does not exist is a no-op, not an error: "already at the default" is not
 * a failure.
 */
final class ResetTemplateOverride
{
    public function handle(Brand $brand, string $key, string $locale): void
    {
        TemplateOverride::withoutBrandScope()
            ->where('brand_id', $brand->getKey())
            ->where('template_key', $key)
            ->where('locale', $locale)
            ->delete();
    }
}

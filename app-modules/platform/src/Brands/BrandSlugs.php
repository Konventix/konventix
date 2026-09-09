<?php

declare(strict_types=1);

namespace Konventix\Platform\Brands;

use Illuminate\Support\Facades\Cache;
use Konventix\Platform\Models\Brand;

/**
 * Brand id to slug for metric labels and log context (ADR-0059). Events
 * carry ids (ADR-0004); dashboards want the name a human recognises.
 *
 * Cached rather than queried on every call — a metric listener must not add
 * a query to every event — but through the cache store, not a static array:
 * `UpdateBrandData` lets an operator rename a brand's slug, and a static
 * array in a long-lived Octane or Horizon worker would keep labelling every
 * metric with the old one until the worker restarted. One cache round-trip
 * per event instead of a query, and at most five minutes of staleness after
 * a rename — which dashboards tolerate fine.
 */
final class BrandSlugs
{
    public const string UNKNOWN = 'unknown';

    public static function of(string $brandId): string
    {
        return Cache::remember(
            'platform:brand-slug:'.$brandId,
            now()->addMinutes(5),
            fn (): string => (string) (Brand::query()->whereKey($brandId)->value('slug') ?? self::UNKNOWN),
        );
    }
}

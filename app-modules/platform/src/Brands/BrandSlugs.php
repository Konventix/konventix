<?php

declare(strict_types=1);

namespace Konventix\Platform\Brands;

use Konventix\Platform\Models\Brand;

/**
 * Brand id to slug for metric labels and log context (ADR-0059). Events
 * carry ids (ADR-0004); dashboards want the name a human recognises. One
 * query per brand per process, then remembered: slugs do not change under a
 * running worker, and a metric listener must not add a query to every event.
 */
final class BrandSlugs
{
    public const string UNKNOWN = 'unknown';

    /** @var array<string, string> */
    private static array $slugs = [];

    public static function of(string $brandId): string
    {
        return self::$slugs[$brandId] ??= (string) (Brand::query()->whereKey($brandId)->value('slug') ?? self::UNKNOWN);
    }

    public static function forget(): void
    {
        self::$slugs = [];
    }
}

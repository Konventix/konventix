<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Konventix\Platform\Models\Brand;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The minimal brand identity the theme editor (and other single-brand admin
 * pages) needs for a heading and breadcrumb — never the full model (ADR-0009).
 */
#[TypeScript]
final class BrandSummaryData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
    ) {}

    public static function fromModel(Brand $brand): self
    {
        return new self(
            id: $brand->getKey(),
            name: $brand->name,
            slug: $brand->slug,
        );
    }
}

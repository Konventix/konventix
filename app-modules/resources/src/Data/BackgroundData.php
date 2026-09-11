<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Numeric;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A raster background under the plan (design §4.4, §8.3): a media-library
 * ULID (ADR-0035, never a URL), how many millimetres one pixel covers, and
 * where its top-left corner sits. `resource_plans.background` is this
 * behind the Eloquent cast, or null.
 */
#[TypeScript]
final class BackgroundData extends Data
{
    public function __construct(
        #[Required, Ulid]
        public readonly string $mediaId,

        #[Required, Numeric, Min(0.001)]
        public readonly float $scaleMmPerPx,

        #[Required, IntegerType]
        public readonly int $offsetXMm = 0,

        #[Required, IntegerType]
        public readonly int $offsetYMm = 0,
    ) {}
}

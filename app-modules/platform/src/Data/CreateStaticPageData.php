<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The new-page form (ADR-0009): title, slug and, on the console's brand-less
 * admin host, the brand it belongs to.
 */
#[TypeScript]
final class CreateStaticPageData extends Data
{
    public function __construct(
        #[Required, StringType, Max(120)]
        public readonly string $title,
        #[Required, StringType, Max(80), Regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')]
        public readonly string $slug,
        /** Explicit brand, else the console selection. */
        #[Nullable, Ulid]
        public readonly ?string $brandId = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace Konventix\Content\Data;

use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The editor-facing configuration of the "latest news" block — one `Data`
 * class serving as validation and generated TS type at once (ADR-0009).
 *
 * The entries themselves are **not** stored in the block: they are resolved at
 * render time, or a page would freeze whichever three articles were newest on
 * the day someone dropped the block in.
 */
#[TypeScript]
final class LatestNewsBlockData extends Data
{
    public function __construct(
        #[Nullable, StringType]
        public readonly ?string $heading = null,

        #[Required, IntegerType, Min(1), Max(12)]
        public readonly int $limit = 3,
    ) {}
}

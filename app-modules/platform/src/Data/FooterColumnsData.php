<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\ArrayType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Present;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The operator's whole set of footer columns for one scope, submitted at
 * once — same replace-all shape as `FooterLinksData`, and the same reason:
 * a short list whose order is part of what the operator is editing.
 *
 * `brandId` picks which set this submit replaces: null for the
 * operator-wide columns, a brand's id for that brand's own.
 */
#[TypeScript]
class FooterColumnsData extends Data
{
    /**
     * @param  array<int, FooterColumnData>  $columns
     */
    public function __construct(
        #[Nullable, Ulid]
        public ?string $brandId = null,
        #[Present, ArrayType, Max(4), DataCollectionOf(FooterColumnData::class)]
        public array $columns = [],
    ) {}
}

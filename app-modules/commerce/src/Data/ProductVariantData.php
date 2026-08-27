<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Actions\SaveProduct;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of the variants repeater on the product editor (ADR-0047, Task 7):
 * a size, a colour, or — when the product carries none — the single implicit
 * row {@see SaveProduct} creates so every product
 * has exactly one stock counter.
 *
 * Doubles as the read shape the editor hydrates from: `remaining` is null for
 * a row the operator just added client-side (nothing has been consumed
 * against a row that does not exist yet) and the computed value for a saved
 * one. `SaveProduct` never reads it — capacity and consumption are the only
 * numbers a save can change.
 */
#[TypeScript]
final class ProductVariantData extends Data
{
    public function __construct(
        /** Null for a row the operator just added; SaveProduct inserts it. */
        #[Nullable, Ulid]
        public readonly ?string $id,
        #[Nullable, StringType, Max(60)]
        public readonly ?string $label,
        #[Nullable, StringType, Max(60)]
        public readonly ?string $sku,
        /** Overrides the product's own price when set. */
        #[Nullable, Regex('/^\d+(\.\d{1,2})?$/')]
        public readonly ?string $unitAmountNet,
        #[Required, IntegerType, Min(0)]
        public readonly int $stockCapacity,
        #[Required, IntegerType, Min(0)]
        public readonly int $position,
        /** Read-only; a save never looks at this. */
        #[Nullable, IntegerType]
        public readonly ?int $remaining = null,
    ) {}
}

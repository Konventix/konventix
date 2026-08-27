<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Actions\SaveProduct;
use Konventix\Commerce\Enums\PriceDisplay;
use Konventix\Commerce\Enums\TaxClass;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\ArrayType;
use Spatie\LaravelData\Attributes\Validation\BooleanType;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Present;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The product editor's save form (ADR-0009, ADR-0047, Task 7) — one Data
 * class for both create and update, per
 * {@see SaveProduct::handle()}. Carries no
 * `brandId`: creation resolves the brand from the console's own selection
 * (`ConsoleSelection`), the way the rest of the operator console does.
 *
 * `$stockCapacity` is read only when `$variants` is empty — it seeds the
 * single implicit variant every product needs (`Product::isSoldOut()`'s
 * contract); once the product carries real variants, each row's own
 * `stockCapacity` is what counts.
 */
#[TypeScript]
final class SaveProductData extends Data
{
    /**
     * @param  list<ProductVariantData>  $variants
     */
    public function __construct(
        #[Required, StringType, Max(120)]
        public readonly string $name,
        #[Required, StringType, Max(80), Regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')]
        public readonly string $slug,
        #[Nullable, StringType]
        public readonly ?string $description,
        #[Nullable, Ulid]
        public readonly ?string $eventId,
        #[Nullable, Ulid]
        public readonly ?string $imageMediaId,
        public readonly TaxClass $taxClass,
        #[Required, Regex('/^\d+(\.\d{1,2})?$/')]
        public readonly string $unitAmountNet,
        public readonly PriceDisplay $priceDisplay,
        #[BooleanType]
        public readonly bool $published,
        #[Nullable, IntegerType, Min(0)]
        public readonly ?int $stockCapacity,
        #[Present, ArrayType, Max(20), DataCollectionOf(ProductVariantData::class)]
        public readonly array $variants,
    ) {}
}

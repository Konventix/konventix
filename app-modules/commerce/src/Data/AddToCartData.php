<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Http\Controllers\CartController;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The shop's add-to-cart form (ADR-0009, ADR-0047, Task 11):
 * {@see CartController::storeLine()}.
 * Quantity is capped at 10 per add — the same ceiling the shop product page
 * already puts on its own quantity input — a genuinely larger order is an
 * operator conversation, not a cart edge case.
 */
#[TypeScript]
final class AddToCartData extends Data
{
    public function __construct(
        #[Required, Ulid]
        public readonly string $variantId,
        #[Required, IntegerType, Min(1), Max(10)]
        public readonly int $quantity,
    ) {}
}

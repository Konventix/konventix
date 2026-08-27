<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Http\Controllers\CartController;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The cart page's quantity-change form (ADR-0009, ADR-0047, Task 11):
 * {@see CartController::updateLine()}.
 * Product lines only — `ChangeCartLineQuantity` refuses a registration
 * line's quantity change with a `LogicException`, which the controller
 * turns into a 422.
 */
#[TypeScript]
final class CartLineQuantityData extends Data
{
    public function __construct(
        #[Required, IntegerType, Min(1), Max(10)]
        public readonly int $quantity,
    ) {}
}

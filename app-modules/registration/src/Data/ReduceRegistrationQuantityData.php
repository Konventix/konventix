<?php

declare(strict_types=1);

namespace Konventix\Registration\Data;

use Konventix\Registration\Enums\QuantityReductionReason;
use Spatie\LaravelData\Attributes\Validation\Enum;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;

/**
 * The console's reduce-quantity dialog (D-REG-15,
 * `RegistrationAdminController::reduceQuantity()`): how many units go back,
 * why, and the operator's own words about it.
 *
 * `units` is only bounded from below here. The upper bound is the
 * registration's own `quantity` minus one, which a static rule set cannot
 * see — `Actions\ReduceRegistrationQuantity` owns it, and refuses "remove
 * everything" by pointing at `CancelRegistration` instead.
 *
 * Inbound only, so no `#[TypeScript]`: the dialog posts these three fields
 * and reads its errors back under their own names, exactly as
 * `RefundOrderData` does.
 */
final class ReduceRegistrationQuantityData extends Data
{
    public function __construct(
        #[Required, IntegerType, Min(1), Max(1000)]
        public readonly int $units,

        #[Required, Enum(QuantityReductionReason::class)]
        public readonly QuantityReductionReason $reasonCode,

        #[Nullable, StringType, Max(500)]
        public readonly ?string $reasonNote = null,
    ) {}
}

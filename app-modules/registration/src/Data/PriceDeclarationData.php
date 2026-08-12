<?php

declare(strict_types=1);

namespace Konventix\Registration\Data;

use Konventix\Registration\Enums\PriceDisplay;
use Konventix\Registration\Enums\TaxClass;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The price declaration on a `RegistrationType` — a value object from the
 * first line of code, not three loose columns (ADR-0017). Registration
 * declares what the offer costs; Commerce computes line totals, tax and
 * rounding and snapshots them onto the order.
 *
 * `unitAmountNet` is a decimal string ("30.00"), never a float — money
 * arithmetic happens in Commerce with bcmath, and a float here would corrupt
 * it before it arrives. The tax *class* (not a percentage) travels with it:
 * Commerce resolves the actual rate at settlement time (ADR-0030).
 */
#[TypeScript]
final class PriceDeclarationData extends Data
{
    /**
     * @param  numeric-string  $unitAmountNet
     */
    public function __construct(
        #[Required, Regex('/^\d+(\.\d{1,2})?$/')]
        public readonly string $unitAmountNet,

        #[Required]
        public readonly TaxClass $taxClass,

        #[Required]
        public readonly PriceDisplay $display,
    ) {}
}

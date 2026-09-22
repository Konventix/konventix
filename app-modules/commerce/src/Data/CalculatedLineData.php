<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Enums\TaxClass;
use Spatie\LaravelData\Data;

/**
 * One order line after Commerce's calculation (ADR-0017: "Commerce computes
 * line totals, tax and rounding, and snapshots the result onto the order").
 * All amounts are decimal strings — money never travels as float.
 */
final class CalculatedLineData extends Data
{
    /**
     * The money fields are `numeric-string`, not merely `string`: every
     * consumer adds them with bcmath, and a plain `string` would let a
     * non-numeric value reach an addition that is only defined for digits.
     *
     * @param  numeric-string  $unitAmountNet
     * @param  numeric-string  $taxRate
     * @param  numeric-string  $lineNet
     * @param  numeric-string  $lineTax
     * @param  numeric-string  $lineGross
     */
    public function __construct(
        public readonly string $label,
        public readonly int $quantity,
        public readonly string $unitAmountNet,
        public readonly TaxClass $taxClass,
        public readonly string $taxRate,
        public readonly string $lineNet,
        public readonly string $lineTax,
        public readonly string $lineGross,
    ) {}
}

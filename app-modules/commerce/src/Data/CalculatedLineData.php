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

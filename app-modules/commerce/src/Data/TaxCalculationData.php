<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Konventix\Commerce\Enums\TaxTreatment;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * The complete result of a settlement-time tax calculation (ADR-0017,
 * ADR-0030): the resolved treatment, every line with its snapshot, and the
 * order totals. This is what gets written onto the `Order`/`OrderLine` rows
 * — and later feeds the invoice document's XML, which must come from this
 * same snapshot, never a second computation (ADR-0031).
 */
final class TaxCalculationData extends Data
{
    /**
     * @param  DataCollection<int, CalculatedLineData>  $lines
     */
    public function __construct(
        public readonly TaxTreatment $treatment,
        public readonly DataCollection $lines,
        public readonly string $totalNet,
        public readonly string $totalTax,
        public readonly string $totalGross,
    ) {}
}

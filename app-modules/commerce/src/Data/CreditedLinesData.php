<?php

declare(strict_types=1);

namespace Konventix\Commerce\Data;

use Spatie\LaravelData\Data;

/**
 * A resolved credit-note selection (#7, D-0.8.2-1): the credited lines and
 * the three totals they sum to.
 *
 * The totals are the **sum of the printed lines**, never a separately
 * rounded figure — `TaxCalculator`'s own guarantee, restated here because
 * it is the property a bookkeeper checks: per line, half-up to cents at
 * intermediate scale 4, then added. There is no second rounding policy
 * beside it.
 */
final class CreditedLinesData extends Data
{
    /**
     * @param  list<CreditedLineData>  $lines
     * @param  numeric-string  $totalNet
     * @param  numeric-string  $totalTax
     * @param  numeric-string  $totalGross
     */
    public function __construct(
        public readonly array $lines,
        public readonly string $totalNet,
        public readonly string $totalTax,
        public readonly string $totalGross,
    ) {}
}

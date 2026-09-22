<?php

declare(strict_types=1);

namespace Konventix\Commerce\Tax;

use Konventix\Commerce\Data\CalculatedLineData;
use Konventix\Commerce\Data\TaxCalculationData;
use Konventix\Commerce\Enums\TaxClass;
use Konventix\Commerce\Enums\TaxTreatment;
use Spatie\LaravelData\DataCollection;

/**
 * Settlement-time pricing and tax calculation (ADR-0017: Registration
 * declares, Commerce computes and snapshots; ADR-0030 for which German VAT
 * cases exist at release).
 *
 * bcmath throughout — amounts are decimal strings end to end. Per-line
 * arithmetic runs at scale 4 and rounds half-up to cents per line
 * (`bcround`), so the order total is exactly the sum of its printed lines —
 * the property a bookkeeper actually checks. Reverse charge and §19 both
 * zero the tax while keeping the resolved treatment on the result: the
 * invoice document needs to know *why* there is no VAT line (ADR-0031).
 */
final class TaxCalculator
{
    /**
     * Public since D-REG-15: `RefundUnitsOnRegistrationQuantityReduced`
     * computes one line's per-unit net and tax with this same arithmetic
     * but the line's *snapshotted* rate rather than a re-resolved class
     * (ADR-0017), so the two must agree on the intermediate scale or a
     * credit note and its invoice line could round apart by a cent.
     */
    public const int SCALE = 4;

    /**
     * @param  list<array{label: string, quantity: int, unit_amount_net: numeric-string, tax_class: string}>  $lines
     */
    public function calculate(
        array $lines,
        bool $operatorSmallBusinessScheme,
        ?string $billingVatId,
        ?string $billingCountryCode,
    ): TaxCalculationData {
        $treatment = GermanVat::treatmentFor(
            operatorSmallBusinessScheme: $operatorSmallBusinessScheme,
            billingVatId: $billingVatId,
            billingCountryCode: $billingCountryCode,
        );

        $calculated = [];
        $totalNet = '0.00';
        $totalTax = '0.00';

        foreach ($lines as $line) {
            $taxClass = TaxClass::from($line['tax_class']);

            $rate = $treatment === TaxTreatment::Standard
                ? GermanVat::rateFor($taxClass)
                : '0.00';

            $calculatedLine = $this->lineAtRate(
                label: $line['label'],
                quantity: $line['quantity'],
                unitAmountNet: $line['unit_amount_net'],
                taxClass: $taxClass,
                taxRate: $rate,
            );

            $calculated[] = $calculatedLine;

            $totalNet = bcadd($totalNet, $calculatedLine->lineNet, 2);
            $totalTax = bcadd($totalTax, $calculatedLine->lineTax, 2);
        }

        return new TaxCalculationData(
            treatment: $treatment,
            lines: new DataCollection(CalculatedLineData::class, $calculated),
            totalNet: $totalNet,
            totalTax: $totalTax,
            totalGross: bcadd($totalNet, $totalTax, 2),
        );
    }

    /**
     * One line at a rate the caller already holds — the same arithmetic
     * `calculate()` runs, with the rate resolution taken out of it.
     *
     * This exists because **a credit note must not re-resolve anything**
     * (#7, D-0.8.2-1). `calculate()` resolves a declared `TaxClass` to
     * today's statutory rate and today's treatment; an order credited next
     * year has to be credited at the rate it was charged at, which is the
     * `order_lines.tax_rate` snapshot (ADR-0017). Every partial credit note
     * therefore comes through here with that snapshot rather than through
     * `calculate()`.
     *
     * Splitting it out rather than restating the formula is the point:
     * rounding is per line, half-up to cents at intermediate scale 4, and
     * there is exactly one place that does it. `D-REG-15`'s unit refund
     * used to carry its own copy of these three lines, and a copy is how
     * two rounding policies come to differ by a cent on a document that
     * cannot be corrected afterwards.
     *
     * A zero rate — reverse charge, §19 UStG — yields zero tax by
     * construction, so no treatment branch is needed here either.
     *
     * @param  numeric-string  $unitAmountNet
     * @param  numeric-string  $taxRate
     */
    public function lineAtRate(
        string $label,
        int $quantity,
        string $unitAmountNet,
        TaxClass $taxClass,
        string $taxRate,
    ): CalculatedLineData {
        $lineNet = bcround(bcmul($unitAmountNet, (string) $quantity, self::SCALE), 2);
        $lineTax = bcround(
            bcdiv(bcmul($lineNet, $taxRate, self::SCALE), '100', self::SCALE),
            2,
        );

        return new CalculatedLineData(
            label: $label,
            quantity: $quantity,
            unitAmountNet: $unitAmountNet,
            taxClass: $taxClass,
            taxRate: $taxRate,
            lineNet: $lineNet,
            lineTax: $lineTax,
            lineGross: bcadd($lineNet, $lineTax, 2),
        );
    }
}

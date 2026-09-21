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

            $lineNet = bcround(bcmul($line['unit_amount_net'], (string) $line['quantity'], self::SCALE), 2);
            $lineTax = bcround(
                bcdiv(bcmul($lineNet, $rate, self::SCALE), '100', self::SCALE),
                2,
            );
            $lineGross = bcadd($lineNet, $lineTax, 2);

            $calculated[] = new CalculatedLineData(
                label: $line['label'],
                quantity: $line['quantity'],
                unitAmountNet: $line['unit_amount_net'],
                taxClass: $taxClass,
                taxRate: $rate,
                lineNet: $lineNet,
                lineTax: $lineTax,
                lineGross: $lineGross,
            );

            $totalNet = bcadd($totalNet, $lineNet, 2);
            $totalTax = bcadd($totalTax, $lineTax, 2);
        }

        return new TaxCalculationData(
            treatment: $treatment,
            lines: new DataCollection(CalculatedLineData::class, $calculated),
            totalNet: $totalNet,
            totalTax: $totalTax,
            totalGross: bcadd($totalNet, $totalTax, 2),
        );
    }
}

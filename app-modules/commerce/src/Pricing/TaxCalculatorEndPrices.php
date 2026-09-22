<?php

declare(strict_types=1);

namespace Konventix\Commerce\Pricing;

use Konventix\Commerce\Enums\TaxClass;
use Konventix\Commerce\Enums\TaxTreatment;
use Konventix\Commerce\Tax\TaxCalculator;
use Konventix\Platform\Data\EndPriceData;
use Konventix\Platform\Operators\OperatorSettings;
use Konventix\Platform\Pricing\EndPriceCalculator;
use RuntimeException;

/**
 * Commerce's answer to Platform's end-price contract (#234, D-COM-20) — the
 * whole of ADR-0017 in one class, and the only thing on a public page that
 * is allowed to turn a declared net amount into money.
 *
 * A contributing module declares what its offer costs — a net amount and a
 * tax *class*, plain scalars — and this computes the end price through the
 * very `TaxCalculator` the product cards, the cart preview and the invoice
 * run through, so the four can only ever agree. The contributor learns no
 * tax rate and Commerce reads no foreign table: the boundary is crossed by
 * scalars, which is what ADR-0004 asks and what the settlement event
 * payload already does.
 *
 * PAngV (#3) is why it has to happen at all: a landing page, `/shop` and
 * the registration form are consumer sales surfaces, so a `gross` price
 * display owes the end price rather than a net amount with a label.
 * Before #234 only `/shop` got one, because only `/shop` ran through a
 * Commerce controller — the card beside it printed the net amount with no
 * note at all.
 *
 * One `TaxCalculator::calculate()` pass per batch, the shape
 * `ShopProductData::ofModel()` uses for a product and its variants. No
 * billing party exists on a public page — a visitor is a consumer until a
 * checkout says otherwise (ADR-0030) — so reverse charge cannot apply here
 * and only the operator's §19 election can move the rate.
 *
 * Every refusal is a `null` entry for that line, never an exception: this
 * runs inside somebody else's public page (see the contract's docblock).
 */
final class TaxCalculatorEndPrices implements EndPriceCalculator
{
    public function __construct(
        private readonly TaxCalculator $taxCalculator,
        private readonly OperatorSettings $operatorSettings,
    ) {}

    /**
     * @param  list<array{unitAmountNet: string, taxClass: string}>  $lines
     * @return list<EndPriceData|null>
     */
    public function endPricesFor(string $brandId, array $lines): array
    {
        /** @var list<EndPriceData|null> $answer */
        $answer = array_fill(0, count($lines), null);

        if ($lines === []) {
            return $answer;
        }

        try {
            $smallBusinessScheme = $this->operatorSettings->sellerForBrand($brandId)->smallBusinessScheme;
            $currency = $this->operatorSettings->currencyForBrand($brandId);
        } catch (RuntimeException) {
            // A brand with no settlement identity yet (no operator row, or
            // no brand at all behind this id) has no end price to give —
            // and a half-configured brand must still be able to show its
            // offers. `ModelNotFoundException` is a `RuntimeException` too,
            // so both cases land here.
            return $answer;
        }

        /** @var list<int> $positions */
        $positions = [];
        /** @var list<array{label: string, quantity: int, unit_amount_net: numeric-string, tax_class: string}> $calculable */
        $calculable = [];

        foreach ($lines as $index => $line) {
            $unitAmountNet = $line['unitAmountNet'];

            // A caller that declares a price owes a decimal string and a
            // tax class (ADR-0017's own shape). Anything else costs that
            // line its end price rather than the whole page: this payload
            // crossed a module boundary before it got here.
            if (! is_numeric($unitAmountNet)) {
                continue;
            }

            // `tryFrom`, not `from`: the calculator does `TaxClass::from()`
            // and an unknown string there is an uncaught `ValueError` that
            // 500s whichever page asked. The declaring module's enum
            // happens to agree with ours today; this is the cross-module
            // scalar boundary and the guard is the point.
            $taxClass = TaxClass::tryFrom($line['taxClass']);

            if ($taxClass === null) {
                continue;
            }

            $positions[] = $index;
            $calculable[] = [
                'label' => '',
                'quantity' => 1,
                'unit_amount_net' => $unitAmountNet,
                'tax_class' => $taxClass->value,
            ];
        }

        if ($calculable === []) {
            return $answer;
        }

        $calculation = $this->taxCalculator->calculate(
            lines: $calculable,
            operatorSmallBusinessScheme: $smallBusinessScheme,
            billingVatId: null,
            billingCountryCode: null,
        );

        foreach ($positions as $position => $index) {
            $calculated = $calculation->lines[$position];

            $answer[$index] = new EndPriceData(
                unitAmountNet: $calculated->unitAmountNet,
                unitAmountGross: $calculated->lineGross,
                taxRate: $calculated->taxRate,
                currency: $currency,
                // What the label may claim (ADR-0030, #244): under the §19
                // election there is no VAT in this amount at all, and the
                // surface has to say so instead of "incl. VAT". Keyed on the
                // resolved treatment, never on a zero rate — the rate is an
                // amount, the election is a status, and only the status
                // makes the §19 sentence true.
                smallBusinessScheme: $calculation->treatment === TaxTreatment::SmallBusiness,
            );
        }

        return array_values($answer);
    }
}

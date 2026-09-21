<?php

declare(strict_types=1);

namespace Konventix\Commerce\Tax;

use InvalidArgumentException;
use Konventix\Commerce\Enums\TaxClass;
use Konventix\Commerce\Enums\TaxTreatment;

/**
 * The release-scope German VAT rules (ADR-0030): standard and reduced
 * rates, reverse charge for B2B billing parties holding a VAT ID in another
 * EU member state, and the operator's §19 UStG small-business election.
 * OSS/cross-border B2C is explicitly out of scope.
 *
 * Rates are constants, not per-type input: the type declares a *class* and
 * Commerce resolves the rate at settlement time (ADR-0017/ADR-0030) — a
 * statutory rate change is a code change here, with the order snapshot
 * keeping everything already sold correct.
 */
final class GermanVat
{
    private const string STANDARD_RATE = '19.00';

    private const string REDUCED_RATE = '7.00';

    /**
     * The Kleinbetragsrechnung ceiling (§33 UStDV, #4): a gross total up to
     * and including this amount may omit the recipient's name and address
     * from the invoice; above it, §14 Abs. 4 UStG's full invoice
     * requirements apply and a billing address becomes mandatory. Gross,
     * not net — the paragraph names the "Gesamtbetrag", the amount
     * actually payable, and it applies to the order total rather than any
     * single line. A legal constant, not a business setting: contrast the
     * operator's own §19 small-business election, which genuinely is a
     * choice — nobody elects out of this one, so it lives beside the VAT
     * rates rather than in `OperatorSettings` or a per-brand row, and
     * public so `PlaceOrderFromCart` and `IssueInvoiceOnOrderPaid` can name
     * it in the messages they show.
     */
    public const string SMALL_AMOUNT_INVOICE_THRESHOLD_GROSS = '250.00';

    /**
     * EU member states (ISO 3166-1 alpha-2), for the intra-EU B2B reverse
     * charge test. Germany is listed but excluded by the domestic check.
     */
    private const array EU_COUNTRY_CODES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR',
        'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL',
        'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    /**
     * @return numeric-string
     */
    public static function rateFor(TaxClass $taxClass): string
    {
        return match ($taxClass) {
            TaxClass::Standard => self::STANDARD_RATE,
            TaxClass::Reduced => self::REDUCED_RATE,
        };
    }

    /**
     * Resolve the treatment (ADR-0030). Precedence: the operator's §19
     * election suppresses VAT entirely regardless of the buyer; otherwise a
     * billing party holding a VAT ID in a *different* EU member state gets
     * reverse charge. Everything else is domestic standard treatment.
     *
     * Reverse charge applies on an unvalidated VAT ID — VIES lookup is an
     * honest, documented gap (ADR-0030 consequences), not a silent one.
     */
    public static function treatmentFor(
        bool $operatorSmallBusinessScheme,
        ?string $billingVatId,
        ?string $billingCountryCode,
        string $sellerCountryCode = 'DE',
    ): TaxTreatment {
        if ($operatorSmallBusinessScheme) {
            return TaxTreatment::SmallBusiness;
        }

        $country = $billingCountryCode === null ? null : strtoupper($billingCountryCode);

        if (
            $billingVatId !== null && $billingVatId !== ''
            && $country !== null
            && $country !== strtoupper($sellerCountryCode)
            && in_array($country, self::EU_COUNTRY_CODES, true)
        ) {
            return TaxTreatment::ReverseCharge;
        }

        return TaxTreatment::Standard;
    }

    /**
     * Whether an order of this gross total legally needs the recipient's
     * postal address on its invoice (§33 UStDV vs §14 Abs. 4 UStG). Exactly
     * at the ceiling still counts as a small-amount invoice — the
     * paragraph's "übersteigt" (exceeds) is strict, so 250.00 itself stays
     * exempt and only a total genuinely above it demands an address.
     *
     * Compared with `bccomp`, never a float cast: a float comparison of
     * money is exactly the bug `TaxCalculator` avoids everywhere else in
     * this class's own callers. `$totalGross` is declared as plain
     * `string`, not `numeric-string` — `TaxCalculationData`/`Order` type
     * their totals the same way — so the `is_numeric()` guard below is
     * what narrows it for `bccomp`, the same idiom
     * `MinorUnits::fromDecimal()` already uses for the same reason.
     */
    public static function requiresBillingAddress(string $totalGross): bool
    {
        if (! is_numeric($totalGross)) {
            throw new InvalidArgumentException(sprintf('Total gross [%s] is not a decimal string.', $totalGross));
        }

        return bccomp($totalGross, self::SMALL_AMOUNT_INVOICE_THRESHOLD_GROSS, 2) > 0;
    }
}

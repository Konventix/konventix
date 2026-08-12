<?php

declare(strict_types=1);

namespace Konventix\Commerce\Tax;

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
}

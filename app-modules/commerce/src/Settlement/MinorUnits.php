<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement;

use InvalidArgumentException;

/**
 * Converts the domain's bcmath decimal strings (ADR-0030) to the integer
 * minor units Stripe and Unzer bill in. Two-decimal currencies only —
 * currency is fixed per brand (decision log Round 6) and the release targets
 * EUR; a zero-decimal currency (JPY) would silently multiply by 100, so it
 * is refused rather than mis-billed.
 */
final class MinorUnits
{
    private const array ZERO_DECIMAL_CURRENCIES = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    public static function fromDecimal(string $amount, string $currency): int
    {
        if (in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Currency [%s] has no minor unit; refusing to convert [%s] as a two-decimal amount.',
                $currency,
                $amount,
            ));
        }

        if (! is_numeric($amount)) {
            throw new InvalidArgumentException(sprintf('Amount [%s] is not a decimal string.', $amount));
        }

        return (int) bcmul($amount, '100', 0);
    }

    /**
     * The inverse, for reading a provider back rather than billing it: a
     * Stripe dispute's `amount` and a charge's `amount_refunded` are minor
     * units, and every domain amount downstream is a decimal string
     * (ADR-0030). Refuses zero-decimal currencies for the same reason
     * `fromDecimal` does — dividing by 100 there would invent cents.
     */
    public static function toDecimal(int $minorUnits, string $currency): string
    {
        if (in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Currency [%s] has no minor unit; refusing to read [%d] as a two-decimal amount.',
                $currency,
                $minorUnits,
            ));
        }

        return bcdiv((string) $minorUnits, '100', 2);
    }
}

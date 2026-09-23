<?php

declare(strict_types=1);

namespace Konventix\Commerce\Documents;

use Konventix\Platform\Locales\Locales;
use NumberFormatter;

/**
 * How the invoice and credit note documents write an amount and a VAT rate:
 * in the locale they render in (ADR-0068 §6), so an English invoice reads
 * `€119.00` and `19.00%` and a German one `119,00 €` and `19,00 %`.
 *
 * Both templates render inside `Locales::within($document->locale)`, so the
 * current application locale *is* the document's locale here. A variant
 * (`de_formal`) formats numbers exactly as its base does, and ICU knows only
 * the base, so the base is what it is handed.
 *
 * The amounts are `decimal(12,2)` strings rounded once at issuance; turning
 * one into a float for display cannot move a cent at that scale.
 */
final class DocumentNumberFormat
{
    public static function money(string $amount, string $currency): string
    {
        $formatted = new NumberFormatter(self::locale(), NumberFormatter::CURRENCY)
            ->formatCurrency((float) $amount, $currency);

        return $formatted === false ? sprintf('%s %s', number_format((float) $amount, 2), $currency) : $formatted;
    }

    /**
     * `$rate` is the stored percentage (`19.00`), not a fraction.
     */
    public static function rate(string $rate): string
    {
        $formatter = new NumberFormatter(self::locale(), NumberFormatter::PERCENT);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 2);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 2);

        $formatted = $formatter->format((float) $rate / 100);

        return $formatted === false ? sprintf('%s %%', number_format((float) $rate, 2)) : $formatted;
    }

    private static function locale(): string
    {
        return app(Locales::class)->base(app()->getLocale());
    }
}

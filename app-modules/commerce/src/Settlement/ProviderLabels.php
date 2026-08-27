<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement;

/**
 * The human-readable settlement label a paid invoice and its mail print
 * (ADR-0048) — `OrderPaid::settlementMethod` is the provider's own scalar
 * key (`Payment::provider`), never something to show a payer verbatim.
 */
final class ProviderLabels
{
    public static function labelFor(string $provider): string
    {
        return match ($provider) {
            'stripe' => 'Stripe',
            'mollie' => 'Mollie',
            'unzer' => 'Unzer',
            'manual' => 'Überweisung / manuell',
            'local' => 'Testzahlung',
            default => ucfirst($provider),
        };
    }
}

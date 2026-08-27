<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement;

/**
 * The human-readable settlement label a paid invoice and its mail print
 * (ADR-0048) — `OrderPaid::settlementMethod` is the provider's own scalar
 * key (`Payment::provider`), never something to show a payer verbatim.
 *
 * `'null'` — `SettlementAccount::PROVIDER_NULL`, the key
 * `LocalHostedCheckoutAdapter::startCheckout()` actually stamps on a
 * `Payment` in `local`/`testing` (a demo brand with no PSP connected, see
 * that class's own docblock) — is a real, exercised case here, not a
 * hypothetical: every dev/demo payment goes through it.
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
            'null' => 'Testzahlung',
            default => ucfirst($provider),
        };
    }
}

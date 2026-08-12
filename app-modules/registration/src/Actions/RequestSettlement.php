<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions;

use Konventix\Registration\Events\RegistrationSettlementRequested;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;
use RuntimeException;

/**
 * Builds and emits `RegistrationSettlementRequested` for a registration
 * whose settlement moves money through the platform (ADR-0026 inverse:
 * `instant`/`invoice` only — the callers guard on
 * `RegistrationSettlement::requestsSettlement()` before invoking this).
 *
 * Not transactional itself: it always runs inside the calling Action's
 * `DB::transaction` (SubmitRegistration for `on_payment` gates,
 * ConfirmRegistration for every other gate, AmendRegistration for a priced
 * amendment).
 *
 * `$requestKey` defaults to the registration's own ULID, which is what the
 * two original call sites want and what makes existing orders backfill
 * cleanly. An amendment passes its own ULID instead so Commerce treats it as
 * a separate order rather than a replay (ADR-0036).
 *
 * `$lines` defaults to the type's price declaration. An amendment overrides
 * it with the delta's own lines: the added grants are priced independently of
 * what the type costs — crew that comes with an exhibitor package is free,
 * while a second booth is not — and `GrantData` carries no price of its own.
 */
final class RequestSettlement
{
    /**
     * @param  list<array{label: string, quantity: int, unit_amount_net: numeric-string, tax_class: string}>|null  $lines
     */
    public function handle(
        Registration $registration,
        RegistrationType $registrationType,
        ?string $requestKey = null,
        ?array $lines = null,
    ): void {
        $price = $registrationType->price_declaration;

        if ($price === null) {
            throw new RuntimeException(sprintf(
                'RegistrationType [%s] declares settlement [%s] but carries no price declaration (ADR-0017) — '
                .'a priced settlement without a price is a misconfigured type.',
                $registrationType->id,
                $registrationType->settlement->value,
            ));
        }

        event(new RegistrationSettlementRequested(
            registrationId: $registration->id,
            registrationTypeId: $registrationType->id,
            eventId: $registration->event_id,
            brandId: $registration->brand_id,
            settlement: $registrationType->settlement->value,
            billingParty: [
                'organization_name' => $registration->organization_name,
                'recipient_name' => $registration->billing_recipient_name,
                'email' => $registration->billing_email,
                'address_line' => $registration->billing_address_line,
                'postal_code' => $registration->billing_postal_code,
                'city' => $registration->billing_city,
                'country_code' => $registration->billing_country_code,
                'vat_id' => $registration->billing_vat_id,
            ],
            lines: $lines ?? [
                [
                    'label' => $registrationType->name,
                    'quantity' => 1,
                    'unit_amount_net' => $price->unitAmountNet,
                    'tax_class' => $price->taxClass->value,
                ],
            ],
            priceDisplay: $price->display->value,
            termsDays: $registrationType->terms_days,
            requestedAt: now()->toIso8601String(),
            requestKey: $requestKey ?? $registration->id,
        ));
    }
}

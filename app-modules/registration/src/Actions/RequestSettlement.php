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
 * ConfirmRegistration for every other gate).
 */
final class RequestSettlement
{
    public function handle(Registration $registration, RegistrationType $registrationType): void
    {
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
            lines: [
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
        ));
    }
}

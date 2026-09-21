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
 * `$enforcesSalesWindow` decides whether the type's sales window travels with
 * the request (#218, fix round 1). It is true for exactly one caller: a fresh
 * registration submitted by someone buying now, which is the only request
 * Commerce may refuse on window grounds. It is false — and both scalars go
 * out null, "not window-bound" — for a waitlist conversion (the spot was
 * promised and its quota held at offer time, D-REG-12), for a confirmation
 * after approval (the organizer let a straggler in on purpose) and for a
 * priced amendment (ADR-0036: the registration already exists and already
 * holds its quota). Registration is the authority on who may register;
 * Commerce enforces the window only for a fresh sale, and only where a payer
 * can still act on the refusal.
 *
 * `$lines` defaults to the type's price declaration, at the registration's
 * own quantity (Task 19): one line, `quantity` units of the type's per-unit
 * net. Commerce multiplies — the cart line and the order line both read
 * `unit_amount_net * quantity` — so the amount stays per unit here and the
 * cart can say "3 × 50,00". An amendment overrides
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
        bool $enforcesSalesWindow = false,
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
            registrantUserId: $registration->registrant_user_id,
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
                    'quantity' => $registration->quantity,
                    'unit_amount_net' => $price->unitAmountNet,
                    'tax_class' => $price->taxClass->value,
                ],
            ],
            priceDisplay: $price->display->value,
            termsDays: $registrationType->terms_days,
            requestedAt: now()->toIso8601String(),
            requestKey: $requestKey ?? $registration->id,
            // #218: Commerce enforces the same window and may not read this
            // module's table to learn it (ADR-0004). Null means "not
            // window-bound" — see this class's docblock for which requests
            // are exempt and why.
            saleStartsAt: $enforcesSalesWindow ? $registrationType->sales_start?->toIso8601String() : null,
            saleEndsAt: $enforcesSalesWindow ? $registrationType->sales_end?->toIso8601String() : null,
        ));
    }
}

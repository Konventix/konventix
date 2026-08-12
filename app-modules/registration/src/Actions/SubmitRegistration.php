<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\PaymentStatus;
use Konventix\Registration\Enums\RegistrationApproval;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Enums\RegistrationVisibility;
use Konventix\Registration\Events\QuotaExhausted;
use Konventix\Registration\Events\RegistrationSubmitted;
use Konventix\Registration\Exceptions\QuotaExhaustedException;
use Konventix\Registration\Models\Quota;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;
use RuntimeException;

/**
 * Accepts a registration form and persists it (ADR-0005, SPEC.md "Owns" —
 * "the approval workflow — submission ..."). Validates visibility and
 * account (D-REG-08 — `docs/inventory/decision-log.md` Round 5: registrants
 * must hold an account), consumes the type's quotas, and emits
 * `RegistrationSubmitted`.
 *
 * When `confirmation_gate: on_submit`, this Action confirms the
 * registration in the same transaction by calling `ConfirmRegistration`
 * (a nested `DB::transaction` becomes a savepoint — Laravel handles this
 * natively) — the gate says confirmation does not wait for approval or
 * payment, whatever the settlement. `confirmation_gate: on_payment` types
 * instead emit `RegistrationSettlementRequested` here at submission (the
 * registrant must be able to pay before anything can confirm); every other
 * gate emits at confirmation — see `ConfirmRegistration`.
 */
final class SubmitRegistration
{
    public function __construct(
        private readonly ConfirmRegistration $confirmRegistration,
        private readonly RequestSettlement $requestSettlement,
    ) {}

    public function handle(
        RegistrationType $registrationType,
        ?string $registrantUserId,
        SubmitRegistrationData $data,
    ): Registration {
        return DB::transaction(function () use ($registrationType, $registrantUserId, $data): Registration {
            $this->guardVisibility($registrationType);
            $this->guardAccount($registrantUserId);
            $this->guardBillingParty($registrationType, $data);

            $this->consumeQuotas($registrationType);

            $registration = Registration::create([
                'registration_type_id' => $registrationType->id,
                'event_id' => $registrationType->event_id,
                'registrant_user_id' => $registrantUserId,
                'status' => RegistrationStatus::Submitted,
                'payment_status' => $registrationType->settlement === RegistrationSettlement::Free
                    ? PaymentStatus::None
                    : PaymentStatus::Pending,
                'organization_name' => $data->organizationName,
                'billing_recipient_name' => $data->billingRecipientName,
                'billing_email' => $data->billingEmail,
                'billing_address_line' => $data->billingAddressLine,
                'billing_postal_code' => $data->billingPostalCode,
                'billing_city' => $data->billingCity,
                'billing_country_code' => $data->billingCountryCode,
                'billing_vat_id' => $data->billingVatId,
                'submitted_at' => now(),
            ]);

            // Already have it — avoids ConfirmRegistration's loadMissing()
            // re-querying a relation this Action already holds.
            $registration->setRelation('registrationType', $registrationType);

            event(new RegistrationSubmitted(
                registrationId: $registration->id,
                registrationTypeId: $registrationType->id,
                eventId: $registrationType->event_id,
                brandId: $registration->brand_id,
                registrantUserId: $registration->registrant_user_id,
                settlement: $registrationType->settlement->value,
                confirmationGate: $registrationType->confirmation_gate->value,
                submittedAt: $registration->submitted_at->toIso8601String(),
            ));

            // An on_payment gate needs an order before anything can confirm:
            // the settlement request goes out at submission. Every other
            // gate requests settlement at confirmation instead (the two
            // emission points partition on the gate — no double emission).
            if (
                $registrationType->confirmation_gate === ConfirmationGate::OnPayment
                && $registrationType->settlement->requestsSettlement()
            ) {
                $this->requestSettlement->handle($registration, $registrationType);
            }

            // Approval is a precondition, not a gate: a type declaring
            // `approval: required` never confirms at submission even if its
            // gate says on_submit — that combination is a misconfigured type,
            // and the safe reading is "wait for approval".
            if (
                $registrationType->confirmation_gate === ConfirmationGate::OnSubmit
                && $registrationType->approval === RegistrationApproval::None
            ) {
                $registration = $this->confirmRegistration->handle($registration);
            }

            return $registration;
        });
    }

    private function guardVisibility(RegistrationType $registrationType): void
    {
        // Link and invite-only tokens are not built yet (TODO.md Phase 2
        // backlog): only Public types can be submitted to in Phase 1.
        if ($registrationType->visibility !== RegistrationVisibility::Public) {
            throw new RuntimeException(sprintf(
                'RegistrationType [%s] is not publicly submittable (visibility: %s).',
                $registrationType->id,
                $registrationType->visibility->value,
            ));
        }
    }

    private function guardAccount(?string $registrantUserId): void
    {
        // D-REG-08: registrants must hold an account. Guest registration is
        // deferred; the controller's `auth` middleware is the first line of
        // defence, this is the Action's own defence in depth.
        if ($registrantUserId === null || $registrantUserId === '') {
            throw new RuntimeException('A registration requires an authenticated account (D-REG-08).');
        }
    }

    /**
     * A legally valid German invoice needs the recipient's postal address,
     * so `settlement: invoice` types require the full billing party at
     * submission. Enforced here rather than in `SubmitRegistrationData`'s
     * static rules because the required-ness depends on which type the URL
     * addressed. `instant` types deliberately stay lenient: a B2C checkout
     * receipt needs no postal address.
     */
    private function guardBillingParty(RegistrationType $registrationType, SubmitRegistrationData $data): void
    {
        if ($registrationType->settlement !== RegistrationSettlement::Invoice) {
            return;
        }

        $missing = array_keys(array_filter([
            'billingRecipientName' => $data->billingRecipientName === null && $data->organizationName === null,
            'billingEmail' => $data->billingEmail === null,
            'billingAddressLine' => $data->billingAddressLine === null,
            'billingPostalCode' => $data->billingPostalCode === null,
            'billingCity' => $data->billingCity === null,
            'billingCountryCode' => $data->billingCountryCode === null,
        ]));

        if ($missing !== []) {
            throw ValidationException::withMessages(array_fill_keys(
                $missing,
                __('This field is required for invoice-settled registrations.'),
            ));
        }
    }

    private function consumeQuotas(RegistrationType $registrationType): void
    {
        /** @var Collection<int, Quota> $quotas */
        $quotas = $registrationType->quotas()->lockForUpdate()->get();

        foreach ($quotas as $quota) {
            if (! $quota->hasCapacityFor(1)) {
                throw QuotaExhaustedException::forQuota($quota);
            }
        }

        foreach ($quotas as $quota) {
            $quota->consume(1);

            if ($quota->remaining() === 0) {
                event(new QuotaExhausted(
                    quotaId: $quota->id,
                    eventId: $registrationType->event_id,
                    brandId: $quota->brand_id,
                    exhaustedAt: now()->toIso8601String(),
                ));
            }
        }
    }
}

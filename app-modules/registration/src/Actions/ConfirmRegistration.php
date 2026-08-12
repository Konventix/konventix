<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Exceptions\IllegalRegistrationStateTransitionException;
use Konventix\Registration\Models\Registration;

/**
 * The one Action that materializes entitlements (ADR-0005, SPEC.md "Public
 * contract" — `RegistrationConfirmed`). Called from inside
 * `SubmitRegistration`'s transaction when `confirmation_gate: on_submit`
 * (and `settlement: free`), and from inside `ApproveRegistration`'s when
 * `confirmation_gate: on_approval`. `confirmation_gate: on_payment` is not
 * wired to anything yet — Commerce's `OrderPaid` consumption is a TODO.md
 * Phase 2 backlog item — so this Action is never called on that path in
 * Phase 1.
 *
 * For settlements that move money (`RegistrationSettlement::requestsSettlement()`),
 * this Action emits `RegistrationSettlementRequested` — except on
 * `confirmation_gate: on_payment`, where `SubmitRegistration` already
 * emitted it at submission (confirmation there is *caused by* the payment,
 * so requesting settlement again would loop). A `settlement: free`
 * registration still never causes a Commerce record (ADR-0026).
 */
final class ConfirmRegistration
{
    public function __construct(
        private readonly RequestSettlement $requestSettlement,
    ) {}

    /**
     * @var list<RegistrationStatus>
     */
    private const array LEGAL_FROM_STATES = [
        RegistrationStatus::Submitted,
        RegistrationStatus::Approved,
    ];

    public function handle(Registration $registration): Registration
    {
        return DB::transaction(function () use ($registration): Registration {
            $this->guardTransition($registration);

            $registration->forceFill([
                'status' => RegistrationStatus::Confirmed,
                'confirmed_at' => now(),
            ])->save();

            // Explicit loadMissing(): Model::shouldBeStrict() (non-production)
            // forbids lazy-loading an unloaded relation through the magic
            // property, so a caller that only had the registration's ID
            // (ApproveRegistration, or a future controller) must not trip it.
            $registrationType = $registration->loadMissing('registrationType')->registrationType;

            event(new RegistrationConfirmed(
                registrationId: $registration->id,
                registrationTypeId: $registrationType->id,
                eventId: $registration->event_id,
                brandId: $registration->brand_id,
                confirmedAt: $registration->confirmed_at->toIso8601String(),
                grants: array_values($registrationType->grants
                    ->toCollection()
                    ->map(fn ($grant): array => [
                        'kind' => $grant->kind->value,
                        'role' => $grant->role,
                        'quantity' => $grant->quantity,
                        'resource_type_id' => $grant->resourceTypeId,
                    ])
                    ->all()),
            ));

            if (
                $registrationType->settlement->requestsSettlement()
                && $registrationType->confirmation_gate !== ConfirmationGate::OnPayment
            ) {
                $this->requestSettlement->handle($registration, $registrationType);
            }

            return $registration->refresh();
        });
    }

    private function guardTransition(Registration $registration): void
    {
        if (! in_array($registration->status, self::LEGAL_FROM_STATES, true)) {
            throw IllegalRegistrationStateTransitionException::forTransition($registration, RegistrationStatus::Confirmed);
        }
    }
}

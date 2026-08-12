<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\PaymentStatus;
use Konventix\Registration\Enums\RegistrationApproval;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Events\RegistrationApproved;
use Konventix\Registration\Exceptions\IllegalRegistrationStateTransitionException;
use Konventix\Registration\Models\Registration;
use RuntimeException;

/**
 * A reviewer approves a submitted registration (ADR-0005, SPEC.md "Owns" —
 * "the approval workflow"). Only legal on a type whose `approval` axis is
 * `Required`; emits `RegistrationApproved`, and confirms in the same
 * transaction (a nested `DB::transaction` becomes a savepoint) when the
 * type's `confirmation_gate` is `OnApproval`.
 */
final class ApproveRegistration
{
    /**
     * @var list<RegistrationStatus>
     */
    private const array LEGAL_FROM_STATES = [
        RegistrationStatus::Submitted,
    ];

    public function __construct(
        private readonly ConfirmRegistration $confirmRegistration,
    ) {}

    public function handle(Registration $registration, string $approvedByUserId): Registration
    {
        return DB::transaction(function () use ($registration, $approvedByUserId): Registration {
            // Explicit loadMissing(): Model::shouldBeStrict() (non-production)
            // forbids lazy-loading an unloaded relation through the magic
            // property, and a caller here only ever has the registration's ID.
            $registration->loadMissing('registrationType');

            $this->guardApprovalRequired($registration);
            $this->guardTransition($registration);

            $registration->forceFill([
                'status' => RegistrationStatus::Approved,
                'approved_at' => now(),
                'approved_by_user_id' => $approvedByUserId,
            ])->save();

            event(new RegistrationApproved(
                registrationId: $registration->id,
                approvedByUserId: $approvedByUserId,
                approvedAt: $registration->approved_at->toIso8601String(),
            ));

            if ($registration->registrationType->confirmation_gate === ConfirmationGate::OnApproval) {
                $registration = $this->confirmRegistration->handle($registration);
            }

            // The symmetric half of RecordOrderPaidForRegistration's
            // approval-precondition rule: on an on_payment gate whose payment
            // landed before review, approval is the last missing input, so
            // confirmation completes here.
            if (
                $registration->registrationType->confirmation_gate === ConfirmationGate::OnPayment
                && $registration->payment_status === PaymentStatus::Paid
            ) {
                $registration = $this->confirmRegistration->handle($registration);
            }

            return $registration;
        });
    }

    private function guardApprovalRequired(Registration $registration): void
    {
        if ($registration->registrationType->approval !== RegistrationApproval::Required) {
            throw new RuntimeException(sprintf(
                'RegistrationType [%s] does not require approval.',
                $registration->registration_type_id,
            ));
        }
    }

    private function guardTransition(Registration $registration): void
    {
        if (! in_array($registration->status, self::LEGAL_FROM_STATES, true)) {
            throw IllegalRegistrationStateTransitionException::forTransition($registration, RegistrationStatus::Approved);
        }
    }
}

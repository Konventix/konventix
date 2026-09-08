<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\PaymentStatus;
use Konventix\Registration\Enums\RegistrationApproval;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Events\RegistrationPaymentStatusChanged;
use Konventix\Registration\Models\Registration;

/**
 * The decisive input for `confirmation_gate: on_payment` (SPEC.md
 * "Consumes" — `OrderPaid`, ADR-0006): set the payment status to paid and,
 * only if the gate says so, confirm the registration.
 *
 * Takes scalars, not the Commerce event object — the thin
 * `RecordPaymentOnOrderPaid` listener unpacks it, mirroring Admission's
 * `IssueAdmissionsForRegistration` pattern, so "uses only the event
 * payload" stays a structural property.
 *
 * Idempotent: a registration already `Paid` is left untouched, so a
 * replayed `OrderPaid` changes nothing. `withoutBrandScope()` scoped to the
 * payload's own `brandId` (ADR-0010's named escape hatch): the lookup must
 * hold regardless of which brand is ambient when a queued replay runs.
 *
 * Approval stays a precondition, not a gate: on an `approval: required` /
 * `on_payment` type whose payment lands before review, this Action records
 * the payment but does not confirm — `ApproveRegistration` completes the
 * confirmation when the reviewer decides (the symmetric check lives there).
 */
final class RecordOrderPaidForRegistration
{
    public function __construct(
        private readonly ConfirmRegistration $confirmRegistration,
    ) {}

    public function handle(string $registrationId, string $brandId): ?Registration
    {
        return DB::transaction(function () use ($registrationId, $brandId): ?Registration {
            /** @var Registration|null $registration */
            $registration = Registration::withoutBrandScope()
                ->where('brand_id', $brandId)
                ->whereKey($registrationId)
                ->lockForUpdate()
                ->first();

            if ($registration === null || $registration->payment_status === PaymentStatus::Paid) {
                return $registration;
            }

            $previous = $registration->payment_status;

            $registration->forceFill([
                'payment_status' => PaymentStatus::Paid,
                // The hold is over — the money left the cart (#210). Left
                // standing, a stamp still in the future made the
                // confirmation mail this very Action triggers tell a payer
                // who had just paid to go and pay in the cart.
                'cart_hold_expires_at' => null,
            ])->save();

            event(new RegistrationPaymentStatusChanged(
                registrationId: $registration->id,
                brandId: $registration->brand_id,
                previousStatus: $previous->value,
                status: PaymentStatus::Paid->value,
                changedAt: now()->toIso8601String(),
            ));

            $registration->loadMissing('registrationType');
            $registrationType = $registration->registrationType;

            $approvalSatisfied = $registrationType->approval === RegistrationApproval::None
                || $registration->status === RegistrationStatus::Approved;

            if (
                $registrationType->confirmation_gate === ConfirmationGate::OnPayment
                && $registration->status !== RegistrationStatus::Confirmed
                && $approvalSatisfied
            ) {
                $registration = $this->confirmRegistration->handle($registration);
            }

            return $registration;
        });
    }
}

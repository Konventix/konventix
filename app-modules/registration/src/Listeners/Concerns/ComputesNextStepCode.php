<?php

declare(strict_types=1);

namespace Konventix\Registration\Listeners\Concerns;

use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\RegistrationApproval;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;

/**
 * The `nextStep` code both `MailOnRegistrationSubmitted` and
 * `MailOnRegistrationApproved` compute — "what does the registrant still
 * need to do", read off the type's own axes rather than baked into a
 * precomputed sentence (`Data\RegistrationMailData`'s docblock). Checked in
 * this order: an unreviewed submission on a type that requires approval
 * outranks everything else; an `on_payment` gate on an `instant` settlement
 * means payment is what confirms it; any `invoice` settlement still owes an
 * invoice; everything else is already confirmed.
 */
trait ComputesNextStepCode
{
    private function nextStepCode(Registration $registration, RegistrationType $registrationType): string
    {
        if (
            $registrationType->approval === RegistrationApproval::Required
            && $registration->status === RegistrationStatus::Submitted
        ) {
            return 'approval';
        }

        if (
            $registrationType->confirmation_gate === ConfirmationGate::OnPayment
            && $registrationType->settlement === RegistrationSettlement::Instant
        ) {
            return 'pay';
        }

        if ($registrationType->settlement === RegistrationSettlement::Invoice) {
            return 'invoice';
        }

        return 'confirmed';
    }
}

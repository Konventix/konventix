<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Registration\Actions\Concerns\ReleasesQuotas;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Events\RegistrationCancelled;
use Konventix\Registration\Exceptions\IllegalRegistrationStateTransitionException;
use Konventix\Registration\Models\Registration;

/**
 * Cancels the contractual act, by organizer or registrant (ADR-0005, SPEC.md
 * "Owns" — "cancellation and withdrawal"). Legal from any state before
 * `Rejected`/`Cancelled` — including `Confirmed`, unlike `RejectRegistration`
 * which only ever runs pre-confirmation. Releases the quota units the
 * registration held and emits `RegistrationCancelled`.
 *
 * `$cancelledByUserId` is nullable because ADR-0057 point 4 introduced a
 * cancellation with no human behind it: the `commerce:cancel-unpaid-reversals`
 * sweep cancels the order when the repayment deadline passes, `OrderCancelled`
 * carries `cancelledByUserId: null` to say so, and
 * `Listeners\CancelRegistrationOnOrderUndone` passes that through. Null is
 * recorded as null — `registrations.cancelled_by_user_id` has always been
 * nullable — rather than attributed to a synthetic system user, so the audit
 * column never claims a person acted who did not.
 */
final class CancelRegistration
{
    use ReleasesQuotas;

    /**
     * @var list<RegistrationStatus>
     */
    private const array LEGAL_FROM_STATES = [
        RegistrationStatus::Submitted,
        RegistrationStatus::Approved,
        RegistrationStatus::Confirmed,
    ];

    public function handle(Registration $registration, ?string $cancelledByUserId, string $reasonCode): Registration
    {
        return DB::transaction(function () use ($registration, $cancelledByUserId, $reasonCode): Registration {
            $this->guardTransition($registration);

            $this->releaseQuotas($registration);

            $registration->forceFill([
                'status' => RegistrationStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $cancelledByUserId,
                'reason_code' => $reasonCode,
            ])->save();

            event(new RegistrationCancelled(
                registrationId: $registration->id,
                cancelledByUserId: $cancelledByUserId,
                reasonCode: $reasonCode,
                cancelledAt: $registration->cancelled_at->toIso8601String(),
            ));

            return $registration->refresh();
        });
    }

    private function guardTransition(Registration $registration): void
    {
        if (! in_array($registration->status, self::LEGAL_FROM_STATES, true)) {
            throw IllegalRegistrationStateTransitionException::forTransition($registration, RegistrationStatus::Cancelled);
        }
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Registration\Actions\Concerns\ReleasesQuotas;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Events\RegistrationRejected;
use Konventix\Registration\Exceptions\IllegalRegistrationStateTransitionException;
use Konventix\Registration\Models\Registration;

/**
 * A reviewer rejects a submitted registration (ADR-0005, SPEC.md "Owns" —
 * "the approval workflow"). Releases the quota units the registration
 * consumed at submission time and emits `RegistrationRejected`.
 */
final class RejectRegistration
{
    use ReleasesQuotas;

    /**
     * @var list<RegistrationStatus>
     */
    private const array LEGAL_FROM_STATES = [
        RegistrationStatus::Submitted,
    ];

    public function handle(Registration $registration, string $rejectedByUserId, string $reasonCode): Registration
    {
        return DB::transaction(function () use ($registration, $rejectedByUserId, $reasonCode): Registration {
            $this->guardTransition($registration);

            $this->releaseQuotas($registration);

            $registration->forceFill([
                'status' => RegistrationStatus::Rejected,
                'rejected_at' => now(),
                'rejected_by_user_id' => $rejectedByUserId,
                'reason_code' => $reasonCode,
            ])->save();

            event(new RegistrationRejected(
                registrationId: $registration->id,
                rejectedByUserId: $rejectedByUserId,
                reasonCode: $reasonCode,
                rejectedAt: $registration->rejected_at->toIso8601String(),
            ));

            return $registration->refresh();
        });
    }

    private function guardTransition(Registration $registration): void
    {
        if (! in_array($registration->status, self::LEGAL_FROM_STATES, true)) {
            throw IllegalRegistrationStateTransitionException::forTransition($registration, RegistrationStatus::Rejected);
        }
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Resources\Listeners;

use Konventix\Registration\Events\RegistrationCancelled;
use Konventix\Resources\Actions\ReleaseBookingsForRegistration;
use Konventix\Resources\Actions\ReleaseSeatClaims;
use Konventix\Resources\Enums\BookingState;

/**
 * A cancelled registration gives everything back (seating design §7.2,
 * §7.5): every held or confirmed booking, through S0's
 * `ReleaseBookingsForRegistration` with reason `registration_cancelled`,
 * and — since this slice — every live seat claim.
 *
 * Deliberately ungated (ADR-0060's explicit ADR-0003 exception): a release
 * is safe and idempotent for a brand that switched the module off, and the
 * event carries no brand id to gate on anyway.
 */
final class ReleaseBookingsOnRegistrationCancelled
{
    public function __construct(
        private readonly ReleaseBookingsForRegistration $action,
        private readonly ReleaseSeatClaims $releaseSeatClaims,
    ) {}

    public function handle(RegistrationCancelled $event): void
    {
        $this->action->handle(
            registrationId: $event->registrationId,
            to: BookingState::Cancelled,
            reason: ReleaseBookingsForRegistration::REASON_REGISTRATION_CANCELLED,
        );

        $this->releaseSeatClaims->handle($event->registrationId, ReleaseSeatClaims::REASON_CANCELLED);
    }
}

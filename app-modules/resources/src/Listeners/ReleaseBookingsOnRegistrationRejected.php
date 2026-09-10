<?php

declare(strict_types=1);

namespace Konventix\Resources\Listeners;

use Konventix\Registration\Events\RegistrationRejected;
use Konventix\Resources\Actions\ReleaseBookingsForRegistration;
use Konventix\Resources\Enums\BookingState;

/**
 * A reviewer rejected the registration (SPEC.md "Consumes" —
 * `RegistrationRejected`): same release as a cancellation, with its own
 * reason so history and metrics can tell the two apart. Ungated for the
 * same reason as `ReleaseBookingsOnRegistrationCancelled`. Registered in
 * `ResourcesServiceProvider::registerListeners()`.
 */
final class ReleaseBookingsOnRegistrationRejected
{
    public function __construct(
        private readonly ReleaseBookingsForRegistration $action,
    ) {}

    public function handle(RegistrationRejected $event): void
    {
        $this->action->handle(
            registrationId: $event->registrationId,
            to: BookingState::Cancelled,
            reason: ReleaseBookingsForRegistration::REASON_REGISTRATION_REJECTED,
        );
    }
}

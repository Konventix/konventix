<?php

declare(strict_types=1);

namespace Konventix\Resources\Listeners;

use Konventix\Registration\Events\RegistrationCancelled;
use Konventix\Resources\Actions\ReleaseBookingsForRegistration;
use Konventix\Resources\Enums\BookingState;

/**
 * The contractual act ended, so the bookings it produced end with it
 * (SPEC.md "Consumes" — `RegistrationCancelled`). Every cancellation path
 * — cart expiry, unpaid reversal sweep, whole-order refund, registrant
 * request — funnels through Registration's `CancelRegistration`, so this
 * one listener covers them all.
 *
 * Deliberately **not** `ShortCircuitsInactiveModule`: releasing is safe
 * and idempotent for a brand that has switched Resources off, and a seat
 * that stays booked for a cancelled registration is the worse outcome.
 * ADR-0060 records this as the explicit ADR-0003 exception. Registered in
 * `ResourcesServiceProvider::registerListeners()`.
 */
final class ReleaseBookingsOnRegistrationCancelled
{
    public function __construct(
        private readonly ReleaseBookingsForRegistration $action,
    ) {}

    public function handle(RegistrationCancelled $event): void
    {
        $this->action->handle(
            registrationId: $event->registrationId,
            to: BookingState::Cancelled,
            reason: ReleaseBookingsForRegistration::REASON_REGISTRATION_CANCELLED,
        );
    }
}

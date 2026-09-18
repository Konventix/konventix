<?php

declare(strict_types=1);

namespace Konventix\Resources\Listeners;

use Konventix\Admission\Events\AdmissionRevoked;
use Konventix\Resources\Actions\ReleaseResourceBooking;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Models\ResourceBooking;

/**
 * A revoked admission gives its place back (#292 §6.5): the seat was the
 * ticket's, and a ticket the door will refuse has no business parking a
 * chair. Only bookings *bound* to the admission — a registration-level
 * booking is the registration's and goes with the registration
 * (`ReleaseBookingsOnRegistrationCancelled`), never with one of its
 * tickets.
 *
 * Deliberately ungated, for the reason the other release listeners are
 * (`.ai/rules/seating.md`): a release is safe and idempotent for a brand
 * that switched the module off. A holder merely changing
 * (`AdmissionHolderUnassigned`) is not handled here on purpose — the next
 * holder inherits the seat.
 */
final class ReleaseBookingOnAdmissionRevoked
{
    public const string REASON = 'admission_revoked';

    public function __construct(private readonly ReleaseResourceBooking $release) {}

    public function handle(AdmissionRevoked $event): void
    {
        $bookings = ResourceBooking::withoutBrandScope()
            ->where('brand_id', $event->brandId)
            ->where('admission_id', $event->admissionId)
            ->whereIn('state', BookingState::occupyingValues())
            ->get();

        foreach ($bookings as $booking) {
            $this->release->handle($booking, BookingState::Released, self::REASON);
        }
    }
}

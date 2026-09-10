<?php

declare(strict_types=1);

namespace Konventix\Resources\Events;

/**
 * A seat claim is gone for good — its registration was cancelled or
 * rejected (seating design §7.5). Its bookings are released separately
 * through `ResourceBookingReleased`; this event says the entitlement itself
 * ended, so nothing offers the picker again.
 *
 * `reason` is one of `registration_cancelled`, `registration_rejected`.
 * Payload is ULIDs and scalars only (ADR-0004).
 */
final readonly class SeatClaimReleased
{
    public function __construct(
        public string $claimId,
        public string $registrationId,
        public string $eventId,
        public string $brandId,
        public string $reason,
    ) {}
}

<?php

declare(strict_types=1);

namespace Konventix\Resources\Events;

/**
 * A confirmed registration's seating grant became a seat claim, unplaced
 * (seating design §9.4). The registrant may now pick; the operator watches
 * `resource_seat_claims_unplaced`.
 *
 * Payload is ULIDs only (ADR-0004).
 */
final readonly class SeatClaimCreated
{
    public function __construct(
        public string $claimId,
        public string $registrationId,
        public string $eventId,
        public string $brandId,
    ) {}
}

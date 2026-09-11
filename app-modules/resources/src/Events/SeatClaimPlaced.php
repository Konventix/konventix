<?php

declare(strict_types=1);

namespace Konventix\Resources\Events;

/**
 * A seat claim was placed on concrete places — its bookings are confirmed
 * (seating design §9.4). Emitted after the placing transaction committed;
 * a re-pick emits it again with the new set.
 *
 * Payload is ULIDs only (ADR-0004).
 */
final readonly class SeatClaimPlaced
{
    /**
     * @param  list<string>  $placeIds
     */
    public function __construct(
        public string $claimId,
        public string $registrationId,
        public string $eventId,
        public string $brandId,
        public array $placeIds,
    ) {}
}

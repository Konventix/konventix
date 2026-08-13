<?php

declare(strict_types=1);

namespace Konventix\Resources\Events;

/**
 * A booking became firm — **the event other modules may act on**.
 *
 * Resources' SPEC settles that this, not `ResourceBookingCreated`, is the
 * name in the contract, because only a confirmed booking is real enough to
 * print on a badge or bill for.
 *
 * Payload is scalars/ULIDs only (ADR-0004).
 */
final readonly class ResourceBookingConfirmed
{
    public function __construct(
        public string $bookingId,
        public string $resourceId,
        public string $brandId,
        public string $holderType,
        public string $holderId,
        public ?string $registrationId,
        public int $quantity,
        public string $startsAt,
        public string $endsAt,
        public string $confirmedAt,
    ) {}
}

<?php

declare(strict_types=1);

namespace Konventix\Resources\Events;

/**
 * A provisional hold was placed on a resource (SPEC.md "Public contract").
 *
 * Deliberately distinct from `ResourceBookingConfirmed`: a hold expires and
 * may never become firm, so nothing irreversible may react to it. Resources'
 * SPEC settles the naming for exactly this reason — "a hold is provisional and
 * must never reach a badge".
 *
 * Payload is scalars/ULIDs only (ADR-0004).
 */
final readonly class ResourceBookingHeld
{
    public function __construct(
        public string $bookingId,
        public string $resourceId,
        public string $brandId,
        public string $holderType,
        public string $holderId,
        public int $quantity,
        public string $startsAt,
        public string $endsAt,
        public string $holdExpiresAt,
    ) {}
}

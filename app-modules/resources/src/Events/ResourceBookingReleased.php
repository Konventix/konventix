<?php

declare(strict_types=1);

namespace Konventix\Resources\Events;

/**
 * A booking gave its interval back — released, cancelled or expired.
 *
 * One event with a `reason` rather than three: every consumer cares that the
 * slot is free again, and none of them so far cares *why*. Splitting it would
 * make each of them handle three events to learn one fact.
 *
 * Payload is scalars/ULIDs only (ADR-0004).
 */
final readonly class ResourceBookingReleased
{
    public function __construct(
        public string $bookingId,
        public string $resourceId,
        public string $brandId,
        public string $holderType,
        public string $holderId,
        public string $reason,
        public string $releasedAt,
    ) {}
}

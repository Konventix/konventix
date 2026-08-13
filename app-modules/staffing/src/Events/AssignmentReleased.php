<?php

declare(strict_types=1);

namespace Konventix\Staffing\Events;

/**
 * An assignment that was held is given back — by the staffer or by a lead.
 *
 * Distinct from declining an offer: declining refuses something never held,
 * releasing gives back something that was, and only the second leaves a hole
 * in a published roster that someone has to fill.
 *
 * Payload is scalars/ULIDs only (ADR-0004).
 */
final readonly class AssignmentReleased
{
    public function __construct(
        public string $assignmentId,
        public string $shiftId,
        public string $eventId,
        public string $brandId,
        public string $userId,
        public string $reason,
        public string $releasedAt,
    ) {}
}

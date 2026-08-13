<?php

declare(strict_types=1);

namespace Konventix\Staffing\Events;

/**
 * A shift role dropped below the headcount it needs.
 *
 * Emitted when a release opens a gap, not on a schedule: the useful moment to
 * tell a lead is when the roster *changes*, not every hour that it remains
 * short. A shift published understaffed is a plan, not an incident.
 *
 * Payload is scalars/ULIDs only (ADR-0004).
 */
final readonly class ShiftUnderstaffed
{
    public function __construct(
        public string $shiftId,
        public string $shiftRoleId,
        public string $eventId,
        public string $brandId,
        public int $requiredHeadcount,
        public int $acceptedHeadcount,
        public string $detectedAt,
    ) {}
}

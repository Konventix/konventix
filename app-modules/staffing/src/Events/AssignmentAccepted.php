<?php

declare(strict_types=1);

namespace Konventix\Staffing\Events;

/**
 * Someone confirmed they are working a shift (SPEC.md "Public contract").
 *
 * **This is the event Admission listens to** (D-STF-01): an accepted
 * assignment auto-creates the crew admission through the event contract —
 * Staffing emits, Admission listens, and Staffing never writes to Admission
 * directly.
 *
 * `role` is the admission role the crew badge should carry, decided here
 * because Staffing knows what the person is rostered as; Admission only knows
 * what to print.
 *
 * Payload is scalars/ULIDs only (ADR-0004).
 */
final readonly class AssignmentAccepted
{
    public function __construct(
        public string $assignmentId,
        public string $shiftId,
        public string $shiftRoleId,
        public string $eventId,
        public string $brandId,
        public string $userId,
        public string $role,
        public string $startsAt,
        public string $endsAt,
        public string $acceptedAt,
    ) {}
}

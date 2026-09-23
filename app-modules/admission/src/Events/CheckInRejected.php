<?php

declare(strict_types=1);

namespace Konventix\Admission\Events;

/**
 * A scan was refused at a door. Emitted by the shared check-in core,
 * `Konventix\Admission\Actions\RecordCheckIn`, which the door reaches
 * through `RecordScan`.
 *
 * A rejection is a first-class event, not a swallowed error: the Admission
 * backlog asks for a full accepted/rejected audit trail, and door staff
 * arguing with an attendee need the reason to have been recorded.
 *
 * `admissionId` is nullable because the commonest rejection — a token whose
 * signature does not verify — never resolves to an admission at all.
 *
 * Payload is scalars/ULIDs only (ADR-0004).
 */
final readonly class CheckInRejected
{
    public function __construct(
        public string $checkInId,
        public ?string $admissionId,
        public string $eventId,
        public string $brandId,
        public ?string $checkpointId,
        public string $reasonCode,
        public string $scannedAt,
    ) {}
}

<?php

declare(strict_types=1);

namespace Konventix\Admission\Events;

/**
 * An admission was accepted at a door. Emitted by
 * `Konventix\Admission\Actions\RecordScan`.
 *
 * **`registrationId` is the reason this event's shape matters beyond
 * Admission.** ADR-0037 makes a checked-in admission block a refund, and
 * D-COM-15 settles that Commerce answers that question from its own read
 * model fed by this event — never by reading Admission's tables. Slice 7's
 * refund flow is blocked until this ships, which is why it leads Slice 5.
 * Removing or renaming this field breaks that contract.
 *
 * `eventDayId` is nullable only because an event may have no day calendar at
 * all; a scan that fell outside a defined calendar is rejected and never
 * reaches this event (D-ADM-04).
 *
 * Payload is scalars/ULIDs only (ADR-0004).
 */
final readonly class AdmissionCheckedIn
{
    public function __construct(
        public string $checkInId,
        public string $admissionId,
        public string $registrationId,
        public string $eventId,
        public string $brandId,
        public ?string $eventDayId,
        public ?string $checkpointId,
        public string $direction,
        public string $scannedAt,
    ) {}
}

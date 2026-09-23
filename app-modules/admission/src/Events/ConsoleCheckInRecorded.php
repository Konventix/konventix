<?php

declare(strict_types=1);

namespace Konventix\Admission\Events;

use Konventix\Compliance\Audit\AuditableEvent;
use Konventix\Compliance\Audit\AuditEntry;

/**
 * An operator checked a ticket in or out from the console, and the shared
 * core wrote a row for it — accepted, or rejected with the door's reason
 * (attendee-list design §3.1). Emitted by `Actions\CheckInFromConsole`
 * through `DB::afterCommit()`, so it never announces a row that rolled back.
 *
 * Auditable (Round 6 D-BATCH): letting a named person in, or out, on another
 * person's word is a privileged act, and the audit trail records who did it.
 * Admission declares its own event worth recording; Compliance's subscriber
 * writes the entry. A console attempt refused before anything was written
 * (`ConsoleCheckInRefusedException`) raises nothing — there was no act.
 *
 * `AdmissionCheckedIn`/`CheckInRejected` still fire from the core, exactly as
 * for a door scan; this event is the console's own addition, not a
 * replacement for either.
 *
 * `result` is `CheckInResult`'s backing string, `reasonCode` the rejection
 * reason's (null on an accepted row). Payload is scalars/ULIDs only
 * (ADR-0004).
 */
final readonly class ConsoleCheckInRecorded implements AuditableEvent
{
    public function __construct(
        public string $checkInId,
        public string $admissionId,
        public string $eventId,
        public string $brandId,
        public string $actorUserId,
        public string $direction,
        public string $result,
        public ?string $reasonCode,
    ) {}

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: $this->direction === 'out' ? 'admission.console-check-out' : 'admission.console-check-in',
            subjectId: $this->admissionId,
            brandId: $this->brandId,
            actorUserId: $this->actorUserId,
            context: [
                'result' => $this->result,
                'reason_code' => $this->reasonCode,
                'check_in_id' => $this->checkInId,
            ],
        );
    }
}

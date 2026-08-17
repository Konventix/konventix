<?php

declare(strict_types=1);

namespace Konventix\Registration\Events;

use Konventix\Compliance\Audit\AuditableEvent;
use Konventix\Compliance\Audit\AuditEntry;

/**
 * A reviewer approved a registration on an `approval: required` type
 * (SPEC.md "Public contract" table). Emitted by
 * `Konventix\Registration\Actions\ApproveRegistration`.
 *
 * Payload is scalars/ULIDs only (ADR-0004) — never an Eloquent model.
 *
 * Auditable (Round 6 D-BATCH): approval is a privileged decision about
 * another person, so Registration declares its own event worth recording and
 * Compliance's subscriber writes the row. Registration never calls into
 * Compliance to log anything.
 */
final class RegistrationApproved implements AuditableEvent
{
    public function __construct(
        public readonly string $registrationId,
        public readonly string $approvedByUserId,
        public readonly string $approvedAt,
    ) {}

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'registration.approved',
            subjectId: $this->registrationId,
            actorUserId: $this->approvedByUserId,
        );
    }
}

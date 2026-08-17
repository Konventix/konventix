<?php

declare(strict_types=1);

namespace Konventix\Registration\Events;

use Konventix\Compliance\Audit\AuditableEvent;
use Konventix\Compliance\Audit\AuditEntry;

/**
 * A reviewer rejected a registration (SPEC.md "Public contract" table).
 * Emitted by `Konventix\Registration\Actions\RejectRegistration`.
 *
 * Payload is scalars/ULIDs only (ADR-0004) — never an Eloquent model.
 *
 * Auditable, for the same reason as approval — and more so: a rejection is
 * the decision a rejected registrant is most likely to dispute.
 */
final class RegistrationRejected implements AuditableEvent
{
    public function __construct(
        public readonly string $registrationId,
        public readonly string $rejectedByUserId,
        public readonly string $reasonCode,
        public readonly string $rejectedAt,
    ) {}

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'registration.rejected',
            subjectId: $this->registrationId,
            actorUserId: $this->rejectedByUserId,
            context: ['reason_code' => $this->reasonCode],
        );
    }
}

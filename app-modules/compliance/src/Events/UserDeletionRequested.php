<?php

declare(strict_types=1);

namespace Konventix\Compliance\Events;

use Konventix\Compliance\Audit\AuditableEvent;
use Konventix\Compliance\Audit\AuditEntry;

/**
 * Emitted when a deletion request is accepted and fans out (SPEC "Public
 * contract"). `mode` is DeletionMode::Anonymize->value or
 * DeletionMode::Erase->value. Every registered GdprHandler is expected to
 * react to this; handlers must be idempotent so this event may be
 * re-dispatched safely (see RedispatchSubjectRequest).
 */
final class UserDeletionRequested implements AuditableEvent
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $subjectId,
        public readonly string $brandId,
        public readonly string $mode,
    ) {}

    /**
     * Auditable: this is the moment a person's records start being rewritten
     * across every module, and re-dispatch fires it again — so the trail
     * shows each attempt rather than one summary.
     */
    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'compliance.deletion.dispatched',
            subjectId: $this->subjectId,
            brandId: $this->brandId,
            context: [
                'request_id' => $this->requestId,
                'mode' => $this->mode,
            ],
        );
    }
}

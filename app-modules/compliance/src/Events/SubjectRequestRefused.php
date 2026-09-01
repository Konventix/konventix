<?php

declare(strict_types=1);

namespace Konventix\Compliance\Events;

use Konventix\Compliance\Audit\AuditableEvent;
use Konventix\Compliance\Audit\AuditEntry;

/**
 * Emitted when an operator refuses a subject request, with the legal ground
 * (SPEC "Public contract").
 *
 * A refusal is a legitimate outcome, not an error: Art. 12(5) GDPR allows
 * refusing manifestly unfounded or excessive requests, and Art. 17(3)(b)
 * keeps data that a legal obligation requires. `reasonCode` is what makes
 * the refusal answerable to a supervisory authority later, which is why it
 * is required rather than free text on the side.
 *
 * `subjectId`/`subjectEmail` mirror the other three subject-request events
 * (ADR-0050 §3): exactly one is set, because the console can refuse a
 * request filed by a subject with no account behind it just as it can an
 * account-backed one.
 */
final class SubjectRequestRefused implements AuditableEvent
{
    public function __construct(
        public readonly string $requestId,
        public readonly ?string $subjectId,
        public readonly ?string $subjectEmail,
        public readonly string $brandId,
        public readonly string $reasonCode,
    ) {}

    /**
     * Auditable above all others: refusing a data subject is the decision a
     * supervisory authority is most likely to ask about, and the trail is
     * where the answer lives.
     */
    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'compliance.subject-request.refused',
            subjectId: $this->subjectId,
            brandId: $this->brandId,
            context: [
                'request_id' => $this->requestId,
                'reason_code' => $this->reasonCode,
            ],
        );
    }
}

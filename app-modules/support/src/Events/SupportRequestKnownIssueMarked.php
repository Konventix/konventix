<?php

declare(strict_types=1);

namespace Konventix\Support\Events;

use Konventix\Compliance\Audit\AuditableEvent;
use Konventix\Compliance\Audit\AuditEntry;

/**
 * Staff marked a report as the canonical instance of a known problem, or
 * unmarked it (design §10.4, §9.4).
 *
 * The flag is the only thing in this module that changes what an
 * *unrelated* visitor sees: a marked request's notice is what the dialog's
 * banner shows to the next person about to report the same thing. That is
 * why it is its own event and its own audit entry rather than a column
 * change nobody can trace.
 *
 * Scalars only (ADR-0004); the notice text is not in the payload for the
 * same reason the message body is not.
 */
final class SupportRequestKnownIssueMarked implements AuditableEvent
{
    public function __construct(
        public readonly string $requestId,
        public readonly ?string $brandId,
        public readonly bool $isKnownIssue,
        public readonly string $actorUserId,
    ) {}

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'support.request.known-issue-marked',
            subjectId: $this->requestId,
            brandId: $this->brandId,
            actorUserId: $this->actorUserId,
            context: [
                'is_known_issue' => $this->isKnownIssue,
            ],
        );
    }
}

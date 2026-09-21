<?php

declare(strict_types=1);

namespace Konventix\Support\Events;

use Konventix\Compliance\Audit\AuditableEvent;
use Konventix\Compliance\Audit\AuditEntry;

/**
 * A report moved (design §10.4). Emitted by `TransitionSupportRequest` for
 * every move including the automatic `received → seen` the first console
 * open performs — that one matters most, because "nobody has looked at it"
 * is what `support_requests_unseen_oldest_age_seconds` measures and the
 * only way to end it is this event.
 *
 * `from` and `to` are the backed enum values; `to` is the metric label.
 * Scalars only (ADR-0004).
 *
 * Auditable: closing somebody's report is a decision with a name attached.
 */
final class SupportRequestStateChanged implements AuditableEvent
{
    public function __construct(
        public readonly string $requestId,
        public readonly ?string $brandId,
        public readonly string $from,
        public readonly string $to,
        public readonly ?string $actorUserId,
    ) {}

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'support.request.state-changed',
            subjectId: $this->requestId,
            brandId: $this->brandId,
            actorUserId: $this->actorUserId,
            context: [
                'from' => $this->from,
                'to' => $this->to,
            ],
        );
    }
}

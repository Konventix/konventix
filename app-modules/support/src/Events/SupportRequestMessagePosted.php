<?php

declare(strict_types=1);

namespace Konventix\Support\Events;

use Konventix\Compliance\Audit\AuditableEvent;
use Konventix\Compliance\Audit\AuditEntry;

/**
 * A note or a reply was added to a thread (design §10.4).
 *
 * The body is **not** in the payload, and that is the point: an internal
 * staff note is staff-only forever (design §4.2), and an event carrying it
 * would put it into the audit context and any future listener's reach.
 * `visibility` says which kind it was, which is all a consumer needs to
 * decide whether to mail the reporter (S3).
 *
 * `authorUserId` is null for the guest reporter writing through their
 * signed link. Scalars only (ADR-0004).
 */
final class SupportRequestMessagePosted implements AuditableEvent
{
    public function __construct(
        public readonly string $requestId,
        public readonly ?string $brandId,
        public readonly string $messageId,
        public readonly string $visibility,
        public readonly ?string $authorUserId,
    ) {}

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'support.request.message-posted',
            subjectId: $this->requestId,
            brandId: $this->brandId,
            actorUserId: $this->authorUserId,
            context: [
                'message_id' => $this->messageId,
                'visibility' => $this->visibility,
            ],
        );
    }
}

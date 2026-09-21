<?php

declare(strict_types=1);

namespace Konventix\Support\Events;

use Konventix\Compliance\Audit\AuditableEvent;
use Konventix\Compliance\Audit\AuditEntry;

/**
 * A report was stored (design §10.4). Support's one inbound act, and the
 * event every other reaction hangs off: the metric increment per category,
 * the operator notice (S1), the `support.request` log record.
 *
 * Scalars only (ADR-0004) — and here that rule does real work beyond
 * convention: the payload deliberately carries neither the message text nor
 * the reporter's address. A listener that wanted either would be a listener
 * copying personal data out of the one row that is allowed to hold it
 * (design §11), and the log record the event produces would carry it into
 * every log sink.
 *
 * `brandId` is null for a console-filed report (ADR-0063). `categories` are
 * the backed enum values, which is what the metric labels with and what the
 * alert selects on.
 *
 * Auditable: the row is the operator's incident record, and "when did this
 * arrive, under which categories" is exactly what the trail is read for.
 */
final class SupportRequestOpened implements AuditableEvent
{
    /**
     * @param  list<string>  $categories
     */
    public function __construct(
        public readonly string $requestId,
        public readonly ?string $brandId,
        public readonly ?string $userId,
        public readonly array $categories,
        public readonly ?string $release,
    ) {}

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'support.request.opened',
            subjectId: $this->requestId,
            brandId: $this->brandId,
            // The reporter is the actor here, and a guest has no account —
            // null then, which the trail already tolerates.
            actorUserId: $this->userId,
            context: [
                'categories' => implode(',', $this->categories),
                'release' => $this->release,
            ],
        );
    }
}

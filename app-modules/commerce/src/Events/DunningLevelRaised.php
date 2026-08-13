<?php

declare(strict_types=1);

namespace Konventix\Commerce\Events;

/**
 * An overdue invoice escalated to the next dunning level. Emitted by
 * `Konventix\Commerce\Actions\RaiseDunningLevel`.
 *
 * Levels are days-overdue thresholds, not a schedule Commerce enforces: this
 * module records that a level was reached and says so, and Messaging decides
 * what a level means to a recipient. Keeping the decision here and the
 * wording there is the same split `AnnouncementPublished` uses (D-CNT-08).
 *
 * Payload is scalars/ULIDs only (ADR-0004).
 */
final readonly class DunningLevelRaised
{
    public function __construct(
        public string $invoiceId,
        public string $invoiceNumber,
        public string $orderId,
        public string $brandId,
        public int $level,
        public int $daysOverdue,
        public string $raisedAt,
    ) {}
}

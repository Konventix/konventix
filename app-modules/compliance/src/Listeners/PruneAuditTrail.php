<?php

declare(strict_types=1);

namespace Konventix\Compliance\Listeners;

use Konventix\Compliance\Events\RetentionPeriodElapsed;
use Konventix\Compliance\Models\AuditLogEntry;

/**
 * Compliance discharging its own retention rule, through exactly the same
 * contract every other module uses (D-CPL-01: audit trail 36 months).
 *
 * The trail is personal data like any other, and the module that holds it
 * deletes it — even when that module is the one publishing the rule. Reaching
 * into `audit_log_entries` from the sweep itself would have been shorter and
 * would have made Compliance the exception to its own design.
 *
 * Deletes by the cutoff rather than "everything", which is what makes the
 * daily re-emission harmless.
 */
final class PruneAuditTrail
{
    public const string CATEGORY = 'compliance.audit-trail';

    public function handle(RetentionPeriodElapsed $event): void
    {
        if ($event->dataCategory !== self::CATEGORY) {
            return;
        }

        AuditLogEntry::query()
            ->where('occurred_at', '<', $event->cutoffAt)
            ->delete();
    }
}

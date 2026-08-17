<?php

declare(strict_types=1);

namespace Konventix\Messaging\Listeners;

use Konventix\Compliance\Events\RetentionPeriodElapsed;
use Konventix\Messaging\Models\MessageRecord;

/**
 * Messaging discharging Compliance's retention rule for the delivery log
 * (D-CPL-01: 12 months).
 *
 * Compliance publishes the rule and never touches this table — it does not
 * know the delivery log exists, only that a category called
 * `messaging.delivery-log` has a period. This listener recognises the
 * category and deletes Messaging's own rows, which is the whole shape of the
 * retention contract (ADR-0004).
 *
 * Queued sends in flight are left alone: a record still waiting to go out is
 * not a log entry yet, and deleting it would drop the message rather than
 * expire its history.
 *
 * Registered explicitly in MessagingServiceProvider — modular event discovery
 * is off and must stay off (.ai/rules/listeners.md).
 */
final class PruneDeliveryLog
{
    public const string CATEGORY = 'messaging.delivery-log';

    public function handle(RetentionPeriodElapsed $event): void
    {
        if ($event->dataCategory !== self::CATEGORY) {
            return;
        }

        MessageRecord::withoutBrandScope()
            ->when($event->brandId !== null, fn ($query) => $query->where('brand_id', $event->brandId))
            ->where('queued_at', '<', $event->cutoffAt)
            ->where(fn ($query) => $query->whereNotNull('sent_at')->orWhereNotNull('failed_at'))
            ->delete();
    }
}

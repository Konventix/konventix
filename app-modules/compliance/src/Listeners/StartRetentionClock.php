<?php

declare(strict_types=1);

namespace Konventix\Compliance\Listeners;

use Konventix\Compliance\Models\RetentionClock;
use Konventix\Event\Events\EventConcluded;

/**
 * Records when an event ended, so retention rules with an `event_concluded`
 * clock have a date to count from (SPEC "Consumes").
 *
 * Idempotent: an event concludes once, but the event may be re-dispatched or
 * replayed, and a second row would make the same data look younger or older
 * depending on which one a sweep read first.
 *
 * Registered explicitly in ComplianceServiceProvider — modular event
 * discovery is off and must stay off (.ai/rules/listeners.md).
 */
final class StartRetentionClock
{
    public function handle(EventConcluded $event): void
    {
        RetentionClock::query()->updateOrCreate(
            ['event_id' => $event->eventId],
            ['brand_id' => $event->brandId, 'concluded_at' => $event->concludedAt],
        );
    }
}

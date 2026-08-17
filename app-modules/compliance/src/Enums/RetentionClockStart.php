<?php

declare(strict_types=1);

namespace Konventix\Compliance\Enums;

/**
 * When a retention period starts counting.
 *
 * `RecordAge` is the row's own age — right for logs, where every entry ages
 * independently. `EventConcluded` is the end of the event the data belongs
 * to, which is what most participant data actually hangs off: a check-in
 * record from a three-day convention is not "old" on its own, it is old
 * because the convention finished.
 */
enum RetentionClockStart: string
{
    case RecordAge = 'record_age';
    case EventConcluded = 'event_concluded';
}

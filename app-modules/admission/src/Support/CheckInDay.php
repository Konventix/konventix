<?php

declare(strict_types=1);

namespace Konventix\Admission\Support;

/**
 * The event day "in or not" is read on at one moment
 * (`LatestAcceptedCheckIn::day()`).
 *
 * - `dayId` is the day covering the moment, or null when there is none — and
 *   then every accepted row counts.
 * - `outsideEveryDay` says why it is null: the event has a calendar and no
 *   day of it covers the moment. The core refuses every check-in and
 *   check-out then (`outside_event_day`), so the console leaves the decision
 *   to it, and the list marks its state "last known" rather than today's.
 *   False for an event with no calendar at all, which is not day-scoped.
 */
final readonly class CheckInDay
{
    public function __construct(
        public ?string $dayId,
        public bool $outsideEveryDay,
    ) {}
}

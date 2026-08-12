<?php

declare(strict_types=1);

namespace Konventix\Event\Events;

/**
 * Start, end or timezone changed (SPEC.md "Public contract" table). Emitted
 * by `Konventix\Event\Actions\RescheduleEvent`. This is one of the two
 * events consumers care about most — it invalidates schedules, shift plans,
 * bookings and admissions across half the system.
 *
 * `startsAt`/`endsAt` are wall-clock strings (`Y-m-d H:i:s`), matching what
 * the organizer edited — not instants (ADR-0027). Payload is scalars/ULIDs
 * only (ADR-0004) — never an Eloquent model.
 */
final class EventRescheduled
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $startsAt,
        public readonly string $endsAt,
        public readonly string $timezone,
    ) {}
}

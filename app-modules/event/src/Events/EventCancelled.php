<?php

declare(strict_types=1);

namespace Konventix\Event\Events;

/**
 * The event will not take place (SPEC.md "Public contract" table). Emitted
 * by `Konventix\Event\Actions\CancelEvent`. This is one of the two events
 * consumers care about most — it invalidates schedules, shift plans,
 * bookings and admissions across half the system.
 *
 * Payload is scalars/ULIDs only (ADR-0004) — never an Eloquent model.
 */
final class EventCancelled
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $brandId,
        public readonly string $reasonCode,
        public readonly string $cancelledAt,
    ) {}
}

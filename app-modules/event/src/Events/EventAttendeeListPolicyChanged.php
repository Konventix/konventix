<?php

declare(strict_types=1);

namespace Konventix\Event\Events;

/**
 * The event's attendee-list switch changed (ADR-0064 §1): `off`, `members`
 * or `public` — `Enums\AttendeeListVisibility`'s scalar value.
 *
 * Mirrors `EventReentryPolicyChanged` deliberately: Registration renders
 * the list and may not read Event's models (ADR-0004), so this contract
 * event is the only way it learns the switch. The payload carries the
 * complete current value, so consumers replace rather than patch and a
 * replay converges. Also emitted at creation, not only on change — a
 * consumer that only ever hears about *changes* never learns the policy of
 * an event that was right from the start.
 */
final readonly class EventAttendeeListPolicyChanged
{
    public function __construct(
        public string $eventId,
        public string $brandId,
        public string $attendeeListVisibility,
    ) {}
}

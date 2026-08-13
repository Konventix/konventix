<?php

declare(strict_types=1);

namespace Konventix\Event\Events;

/**
 * The event's re-entry policy changed (decision log Round 6, D-ADM-03): the
 * event carries the knob — once-only / once-per-day / unlimited — and
 * checkpoints stay policy-free (ADR-0033).
 *
 * This exists because ADR-0033 requires the policy to be resolvable **at the
 * door with no server round trip**, and ADR-0004 forbids Admission reading
 * Event's models. Publishing it as a contract event is the only way Admission
 * can hold it locally. Deliberately mirrors `EventDaysChanged`: the payload
 * carries the complete current value, so consumers replace rather than patch,
 * and a replay converges instead of drifting.
 *
 * Also emitted at creation, not only on change — a consumer that only ever
 * hears about *changes* never learns the policy of an event whose policy was
 * right from the start.
 */
final readonly class EventReentryPolicyChanged
{
    public function __construct(
        public string $eventId,
        public string $brandId,
        public string $reentryPolicy,
    ) {}
}

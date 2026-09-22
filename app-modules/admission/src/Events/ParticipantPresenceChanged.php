<?php

declare(strict_types=1);

namespace Konventix\Admission\Events;

/**
 * A participant's `last_direction` changed — they scanned in, or the desk
 * checked them out (D-HUB-02, design §4.3). "On site" is a badge, never a
 * gate: this event does not touch hub access, and consumers must not
 * derive it from here. Community's Checked-In presence (D-HUB-03) and
 * Competition's "is your team here?" read this, not Admission's table.
 *
 * `direction` is `in` or `out`; `changedAt` is an ISO-8601 instant.
 * Payload is ULIDs and scalars only (ADR-0004).
 */
final readonly class ParticipantPresenceChanged
{
    public function __construct(
        public string $userId,
        public string $eventId,
        public string $brandId,
        public string $direction,
        public string $changedAt,
    ) {}
}

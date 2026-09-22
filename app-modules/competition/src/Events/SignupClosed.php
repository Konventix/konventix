<?php

declare(strict_types=1);

namespace Konventix\Competition\Events;

/**
 * Signup for a competition is shut (design §4, §12) — closed by the
 * organizer, or by the scheduler once `signup_closes_at` passed.
 *
 * `entrantCount` is the size of the field at the moment it closed: every
 * entrant holding a slot, `incomplete` rosters included, because those still
 * occupy a place until the organizer fills or declines them (§5.4).
 * ULIDs and scalars only (ADR-0004).
 */
final readonly class SignupClosed
{
    public function __construct(
        public string $competitionId,
        public string $eventId,
        public string $brandId,
        public int $entrantCount,
        public string $at,
    ) {}
}

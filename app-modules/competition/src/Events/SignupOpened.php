<?php

declare(strict_types=1);

namespace Konventix\Competition\Events;

/**
 * Signup for a competition is open (design §4, §12): the organizer reopened
 * it from `SignupClosed`, or a `Published` competition's window opened on a
 * scheduler tick.
 *
 * GameOps' SPEC proposed this name for a consumer of its own; it keeps it.
 * ULIDs and scalars only (ADR-0004).
 */
final readonly class SignupOpened
{
    public function __construct(
        public string $competitionId,
        public string $eventId,
        public string $brandId,
        public int $entrantCount,
        public string $at,
    ) {}
}

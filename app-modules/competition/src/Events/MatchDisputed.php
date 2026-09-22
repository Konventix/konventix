<?php

declare(strict_types=1);

namespace Konventix\Competition\Events;

/**
 * The two sides of a match do not agree about what happened (design §8.4,
 * §12).
 *
 * Emitted when a second report contradicts the first, and when a side
 * contests outright. §13 hangs the `competition_disputes_open` metric and the
 * "a dispute older than 30 minutes while the competition is running" alert off
 * this state — an unnoticed dispute is a tournament that has quietly stopped.
 *
 * `entrantId` and `userId` are the side and the person who opened it.
 */
final readonly class MatchDisputed
{
    public function __construct(
        public string $matchId,
        public string $competitionId,
        public string $brandId,
        public string $entrantId,
        public string $userId,
        public string $at,
    ) {}
}

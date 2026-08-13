<?php

declare(strict_types=1);

namespace Konventix\Competition\Standings;

/**
 * An entrant's running record while a league table is being tallied.
 *
 * A small mutable object rather than an array shape: the tally is built by
 * incrementing fields across two passes, which array shapes describe badly —
 * every key is "might not exist" until the loop that creates it has run, and
 * saying otherwise takes an annotation that asserts rather than proves.
 */
final class LeagueRecord
{
    public int $wins = 0;

    public int $losses = 0;

    public int $draws = 0;

    public int $points = 0;

    /** Score for minus score against: the tiebreaker a league ranks on. */
    public int $difference = 0;

    public function __construct(
        public readonly string $entrantId,
        public readonly int $seed,
    ) {}

    /**
     * Whether two records finished level on everything the format ranks on.
     */
    public function ties(self $other): bool
    {
        return $this->points === $other->points && $this->difference === $other->difference;
    }
}

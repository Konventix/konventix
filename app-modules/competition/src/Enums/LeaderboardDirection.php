<?php

declare(strict_types=1);

namespace Konventix\Competition\Enums;

/**
 * Which way a leaderboard's numbers run (design §7.2).
 *
 * Trackmania is `Lower` — a lap time, where 42.117 beats 42.118. Casemod and
 * Fall Guys are `Higher` — jury points and points per heat. One column rather
 * than a per-discipline rule, because the same discipline can be scored
 * either way and only the stage knows which.
 */
enum LeaderboardDirection: string
{
    case Higher = 'higher';
    case Lower = 'lower';

    /**
     * Whether `$candidate` is the better of the two. A strict comparison:
     * equal values are not better, which is what lets two entrants share a
     * placement rather than one of them winning the tie by accident of order.
     */
    public function isBetter(float $candidate, float $incumbent): bool
    {
        return $this === self::Lower
            ? $candidate < $incumbent
            : $candidate > $incumbent;
    }

    /** Console copy is English (ADR-0001 is about brand sites, not this). */
    public function label(): string
    {
        return match ($this) {
            self::Higher => 'Higher is better',
            self::Lower => 'Lower is better',
        };
    }
}

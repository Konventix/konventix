<?php

declare(strict_types=1);

namespace Konventix\Competition\Enums;

/**
 * How a leaderboard entrant's several score entries become one number
 * (design §7.2).
 *
 * The three SXLAN actually needed: Trackmania takes the **best** of a
 * resubmittable time, Fall Guys **sums** its five heats, and Casemod
 * **averages** its jurors. Nothing else, and deliberately no "sum of the best
 * n" — that is a rule nobody has asked for and it would need a second
 * setting to say what n is.
 */
enum LeaderboardAggregate: string
{
    case Best = 'best';
    case Sum = 'sum';
    case Average = 'average';

    /**
     * Reduce one entrant's values to the number the table ranks on.
     *
     * `Best` is the only one that needs the direction: the best of a set of
     * lap times is the smallest and the best of a set of point totals is the
     * largest, and nothing in the values themselves says which.
     *
     * The caller guarantees at least one value: an entrant with none has no
     * aggregate at all and is ranked last rather than reduced to a number
     * (`LeaderboardStandingsCalculator`). `min()`, `max()` and a division by
     * `count()` all misbehave on an empty list, and the honest place to say
     * "there is nothing here" is the ranking, not a sentinel.
     *
     * @param  non-empty-list<float>  $values
     */
    public function of(array $values, LeaderboardDirection $direction): float
    {
        return match ($this) {
            self::Best => $direction === LeaderboardDirection::Lower ? min($values) : max($values),
            self::Sum => array_sum($values),
            self::Average => array_sum($values) / count($values),
        };
    }

    /** Console copy is English (ADR-0001 is about brand sites, not this). */
    public function label(): string
    {
        return match ($this) {
            self::Best => 'Best entry',
            self::Sum => 'Sum of all entries',
            self::Average => 'Average of all entries',
        };
    }
}

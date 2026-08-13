<?php

declare(strict_types=1);

namespace Konventix\Competition\Standings;

/**
 * Standard competition ranking ("1224").
 *
 * Given rows already in display order and a predicate for "these two are
 * genuinely inseparable", assigns the placement each row earned: tied rows
 * share the better number, and the next distinct row skips the placements the
 * tie consumed. Four entrants tied at the top are all 1st and the fifth is
 * 5th.
 *
 * Kept in one place because every format has to answer the same question and
 * the answer must not drift between them — a round robin that shares 2nd while
 * a group stage hands out a 2nd and a 3rd would be reporting two different
 * things under one word.
 */
final class Placements
{
    /**
     * @template TRow
     *
     * @param  list<TRow>  $rows  in display order, best first
     * @param  callable(TRow, TRow): bool  $isTied  true when the pair cannot be separated
     * @return list<int> placement per row, index-aligned with `$rows`
     */
    public static function assign(array $rows, callable $isTied): array
    {
        $placements = [];
        $placement = 1;

        foreach ($rows as $index => $row) {
            if ($index > 0 && ! $isTied($rows[$index - 1], $row)) {
                // Skip the placements the preceding tie consumed.
                $placement = $index + 1;
            }

            $placements[] = $placement;
        }

        return $placements;
    }
}

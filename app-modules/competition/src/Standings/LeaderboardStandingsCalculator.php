<?php

declare(strict_types=1);

namespace Konventix\Competition\Standings;

use Konventix\Competition\Data\LeaderboardSettings;
use Konventix\Competition\Enums\LeaderboardDirection;

/**
 * Ranks a leaderboard stage from its verified score entries (design §7.2).
 *
 * Pure, like every bracket calculator beside it: entrants and entries in,
 * placed rows out, no database. It deliberately does **not** implement
 * `StandingsCalculator` — that interface's second argument is a list of
 * finished matches, and a leaderboard stage has no matches at all. Bending
 * one signature over both would mean passing the score entries through
 * `$settings`, which is a blob by another name (ADR-0009).
 *
 * ## The three rules that decide the table
 *
 * 1. **Only verified entries count** (§8.3). An unverified row is a claim,
 *    not a result, and a table that ranked claims would move every time
 *    somebody typed a number and move back when the desk rejected it.
 * 2. **Only entries belonging to the stage's heat shape count.** A stage
 *    without heats takes the one null-heat entry per entrant; a stage with
 *    five takes heats 1..5. A heat number on a heatless stage, or a sixth
 *    heat on a five-heat stage, is a row that should never have been written
 *    and is ignored rather than quietly counted.
 * 3. **An entrant with no counting entry ranks last, both ways round.** This
 *    is the rule the direction makes dangerous: on a `lower` leaderboard the
 *    obvious "missing means zero" would put everyone who never submitted a
 *    lap time at the top of the table, ahead of the person who actually
 *    drove. Nothing is not a score.
 *
 * ## Ties
 *
 * Aggregates are compared **exactly**, not rounded to the stage's `decimals`:
 * those are display settings (`LeaderboardSettings`), and rounding before
 * comparing would invent ties the stopwatch did not record. Entrants who are
 * genuinely level share a placement under standard competition ranking, the
 * same "1224" convention every other calculator here uses — seed orders the
 * rows for display but never separates them.
 */
final class LeaderboardStandingsCalculator
{
    /**
     * Two aggregates closer than this are the same number. They arrive from
     * a `decimal(12,3)` column, so the only difference this absorbs is the
     * binary-float error of summing and averaging them.
     */
    private const float EPSILON = 0.000001;

    /**
     * @param  list<StandingsEntrant>  $entrants  the full field, including entrants who never submitted
     * @param  list<ScoredEntry>  $entries  every entry of the stage, verified or not
     * @return list<StandingEntry> in display order, best first
     */
    public function calculate(array $entrants, array $entries, LeaderboardSettings $settings): array
    {
        $values = $this->countingValues($entrants, $entries, $settings);

        $rows = [];

        foreach ($entrants as $entrant) {
            $own = $values[$entrant->id];

            $rows[] = [
                'entrant_id' => $entrant->id,
                'seed' => $entrant->seedOrLast(),
                'entries' => count($own),
                'aggregate' => $own === [] ? null : $settings->aggregate->of($own, $settings->direction),
            ];
        }

        usort($rows, fn (array $a, array $b): int => $this->compare($a, $b, $settings->direction));

        $placements = Placements::assign($rows, fn (array $a, array $b): bool => $this->ties($a, $b));

        $placed = [];

        foreach ($rows as $index => $row) {
            $placed[] = new StandingEntry(
                entrantId: $row['entrant_id'],
                placement: $placements[$index],
                // A leaderboard has no matches, so there is nothing true to
                // put in wins, losses, draws or points — the number the table
                // ranks on is a decimal and `tiebreaker` is the float column.
                // No verified entry, no number: null, never a zero that the
                // public board would print as "0 s" (final review, I6).
                tiebreaker: $row['aggregate'],
            );
        }

        return $placed;
    }

    /**
     * The values that count, per entrant: verified, and belonging to the
     * stage's heat shape.
     *
     * @param  list<StandingsEntrant>  $entrants
     * @param  list<ScoredEntry>  $entries
     * @return array<string, list<float>>
     */
    private function countingValues(array $entrants, array $entries, LeaderboardSettings $settings): array
    {
        $values = [];

        foreach ($entrants as $entrant) {
            $values[$entrant->id] = [];
        }

        foreach ($entries as $entry) {
            if (! $entry->verified) {
                continue;
            }

            if (! $settings->acceptsHeat($entry->heat)) {
                continue;
            }

            if (! array_key_exists($entry->entrantId, $values)) {
                // An entrant who has since left the field. Their entries stay
                // in the table; they just no longer rank.
                continue;
            }

            $values[$entry->entrantId][] = $entry->value;
        }

        return $values;
    }

    /**
     * Display order: everyone with a score, by the direction, then everyone
     * without, by seed. Seed breaks a tie for display only.
     *
     * @param  array{entrant_id: string, seed: int, entries: int, aggregate: float|null}  $a
     * @param  array{entrant_id: string, seed: int, entries: int, aggregate: float|null}  $b
     */
    private function compare(array $a, array $b, LeaderboardDirection $direction): int
    {
        if ($a['aggregate'] === null || $b['aggregate'] === null) {
            // false sorts before true: an entrant with a score outranks one
            // without, whichever way the numbers run.
            return [$a['aggregate'] === null, $a['seed']] <=> [$b['aggregate'] === null, $b['seed']];
        }

        if ($direction->isBetter($a['aggregate'], $b['aggregate'])) {
            return -1;
        }

        if ($direction->isBetter($b['aggregate'], $a['aggregate'])) {
            return 1;
        }

        return $a['seed'] <=> $b['seed'];
    }

    /**
     * Whether two rows are genuinely inseparable. Two entrants who never
     * submitted are; one who did and one who did not never are, however the
     * placement arithmetic would otherwise fall out.
     *
     * @param  array{entrant_id: string, seed: int, entries: int, aggregate: float|null}  $a
     * @param  array{entrant_id: string, seed: int, entries: int, aggregate: float|null}  $b
     */
    private function ties(array $a, array $b): bool
    {
        if ($a['aggregate'] === null || $b['aggregate'] === null) {
            return $a['aggregate'] === null && $b['aggregate'] === null;
        }

        return abs($a['aggregate'] - $b['aggregate']) < self::EPSILON;
    }
}

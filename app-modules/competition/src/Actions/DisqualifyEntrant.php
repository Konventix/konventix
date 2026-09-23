<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\ForfeitsOpenMatches;
use Konventix\Competition\Actions\Concerns\RetiresAnEntrant;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Enums\WithdrawalReason;
use Konventix\Competition\Models\Entrant;

/**
 * An organizer's ruling (design §5.5, `ManageEntrants`).
 *
 * The part that matters is what happens to the bracket. Disqualifying a
 * seeded entrant in a running bracket **forfeits its pending matches, each
 * recorded as a forfeit result through `RecordMatchResult`, so the bracket
 * advances the normal way**. No special "disqualified" edge, no branch in the
 * progression walk, nothing for `AdvanceBracket` to learn: the opponent wins
 * 1–0 and moves on exactly as they would have by playing.
 *
 * That is the whole reason the forfeit is a result rather than a state. A
 * bracket that advanced through a second mechanism would need every
 * standings calculator to know about it, and the one that forgot would
 * silently leave a hole in the table.
 *
 * ## Three shapes of open match (final review, part 1, I2)
 *
 * - **The opponent is there.** Forfeit: 1–0 to the opponent, who advances.
 * - **The opponent is still to come** — the other slot is fed by a match
 *   that is not finished, the common case of disqualifying a team that won
 *   round 1 while its round-2 opponent is still playing. The match is *not*
 *   finished: finishing it with no winner put the opponent, once they
 *   arrived, into a match that was already over, and they never advanced.
 *   Instead the disqualified entrant's slot is **emptied**. Its feeder is
 *   finished, so once the opponent arrives the empty slot reads as a bye
 *   (`SettlesAMatch::guardEverySideHasArrived`) and the desk settles it the
 *   way it settles every bye: by giving the lone side the match (design §14,
 *   "a disqualification in front of an opponent still to come").
 * - **Nobody is coming** — the other slot is a bye nothing will ever fill.
 *   The match is finished with no winner, so the slot it feeds downstream
 *   sits behind a finished feeder and becomes a bye for whoever arrives
 *   there.
 *
 * A forfeit can route the disqualified entrant on as the **loser** — in
 * double elimination, into the losers' bracket. So the pass is a loop that
 * runs until no pending, scheduled or live match holds the entrant any more,
 * rather than a list taken once up front.
 *
 * The standings of every stage the competition plays are recomputed once, at
 * the end: forfeits move wins and points, and a disqualified entrant leaves
 * the table (`CalculateStandings` ranks the field only).
 */
final class DisqualifyEntrant
{
    use ForfeitsOpenMatches;
    use RetiresAnEntrant;

    /**
     * @return list<string> the matches forfeited
     */
    public function handle(Entrant $entrant): array
    {
        return DB::transaction(function () use ($entrant): array {
            $this->lockCompetitionOf($entrant);

            $this->retireEntrant($entrant, EntrantState::Disqualified, WithdrawalReason::Disqualified);

            $forfeited = $this->forfeitOpenMatches($entrant);

            $this->recomputeCompetition($entrant);

            return $forfeited;
        });
    }
}

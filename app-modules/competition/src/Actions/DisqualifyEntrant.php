<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\RetiresAnEntrant;
use Konventix\Competition\Actions\Concerns\SettlesAMatch;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Enums\StageFormat;
use Konventix\Competition\Enums\WithdrawalReason;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\MatchSlot;
use Konventix\Competition\Models\Stage;

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
    use RetiresAnEntrant;
    use SettlesAMatch;

    /**
     * @var list<string>
     */
    private const array OPEN_STATES = [MatchState::Pending->value, MatchState::Scheduled->value, MatchState::Live->value];

    /**
     * @return list<string> the matches forfeited
     */
    public function handle(Entrant $entrant): array
    {
        return DB::transaction(function () use ($entrant): array {
            $this->retireEntrant($entrant, EntrantState::Disqualified, WithdrawalReason::Disqualified);

            $forfeited = $this->forfeitOpenMatches($entrant);

            $this->recomputeCompetition($entrant);

            return $forfeited;
        });
    }

    /**
     * @return list<string>
     */
    private function forfeitOpenMatches(Entrant $entrant): array
    {
        $entrantId = (string) $entrant->getKey();
        $forfeited = [];
        $handled = [];

        while (($match = $this->nextOpenMatch($entrant, $handled)) !== null) {
            // Every pass either finishes the match or takes the entrant out
            // of it, so neither can bring it back; the list is a guard
            // against a loop, not part of the rule.
            $handled[] = $match->id;

            $sides = $this->entrantIdsByPosition($match);

            if ($this->awaitsASide($match, $sides)) {
                MatchSlot::withoutBrandScope()
                    ->where('brand_id', $match->brand_id)
                    ->where('competition_match_id', $match->id)
                    ->where('entrant_id', $entrantId)
                    ->update(['entrant_id' => null]);

                continue;
            }

            $scores = [];
            $winnerId = null;

            foreach ($sides as $sideId) {
                $isForfeiting = $sideId === $entrantId;
                $scores[$sideId] = $isForfeiting ? 0 : 1;

                if (! $isForfeiting) {
                    $winnerId = $sideId;
                }
            }

            app(RecordMatchResult::class)->handle($match, $scores, $winnerId);
            app(AdvanceBracket::class)->handle($match->refresh());

            $forfeited[] = $match->id;
        }

        return $forfeited;
    }

    /**
     * The earliest open match that still holds the entrant.
     *
     * @param  list<string>  $handled
     */
    private function nextOpenMatch(Entrant $entrant, array $handled): ?CompetitionMatch
    {
        /** @var CompetitionMatch|null $match */
        $match = CompetitionMatch::withoutBrandScope()
            ->where('brand_id', $entrant->brand_id)
            ->whereIn('id', MatchSlot::withoutBrandScope()
                ->select('competition_match_id')
                ->where('brand_id', $entrant->brand_id)
                ->where('entrant_id', $entrant->getKey()))
            ->whereIn('state', self::OPEN_STATES)
            ->when($handled !== [], fn ($query) => $query->whereNotIn('id', $handled))
            ->orderBy('round')
            ->orderBy('id')
            ->first();

        return $match;
    }

    /**
     * Every stage that has standings to show: a drawn one, or a leaderboard,
     * which needs no draw (design §14, "A leaderboard needs no draw to
     * start"). Recomputed whether or not a match was forfeited in it — the
     * disqualified entrant leaves the table either way.
     */
    private function recomputeCompetition(Entrant $entrant): void
    {
        $stages = Stage::withoutBrandScope()
            ->where('brand_id', $entrant->brand_id)
            ->where('competition_id', $entrant->competition_id)
            ->where(fn ($query) => $query
                ->whereNotNull('generated_at')
                ->orWhere('format', StageFormat::Leaderboard->value))
            ->orderBy('sequence')
            ->get();

        foreach ($stages as $stage) {
            app(CalculateStandings::class)->handle($stage);
        }
    }
}

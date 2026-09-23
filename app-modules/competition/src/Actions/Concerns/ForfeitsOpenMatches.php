<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions\Concerns;

use Konventix\Competition\Actions\AdvanceBracket;
use Konventix\Competition\Actions\CalculateStandings;
use Konventix\Competition\Actions\RecordMatchResult;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Enums\StageFormat;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\MatchSlot;
use Konventix\Competition\Models\Stage;

/**
 * Takes a retired entrant out of every open match it still stands in
 * (design §5.5; the three shapes are `DisqualifyEntrant`'s docblock).
 *
 * `DisqualifyEntrant` always runs it for the entrant it disqualifies.
 * `ForfeitRetiredEntrants` runs it for every withdrawn or disqualified entrant
 * still in an open match once the competition holds a recorded result — after
 * a withdrawal, a decline, a disqualification and every settle: from then on
 * the desk can no longer draw again, so a retired team left standing in its
 * slots would strand the bracket, and before the start the start itself (fix
 * wave A rounds 2 and 3, N2 and M-f).
 */
trait ForfeitsOpenMatches
{
    use RefusesOnceAResultIsRecorded;
    use SettlesAMatch;

    /**
     * The competition's row lock, taken first — before the entrant is
     * retired or a slot emptied — in the order every other writer takes its
     * locks (`GuardsCompetitionLifecycle`), so a forfeit pass and a desk
     * write cannot deadlock (fix wave A round 2, M-b).
     *
     * Then the entrant is re-read under a lock of its own into the instance
     * the caller holds (`CheckInEntrant`'s idiom): of two withdraw or
     * disqualify submits, the second waits here and then finds the entrant
     * already gone, instead of sending a second `EntrantWithdrawn` and a
     * second roster notice (fix wave A round 3, M-g).
     */
    protected function lockCompetitionOf(Entrant $entrant): void
    {
        Competition::withoutBrandScope()->whereKey($entrant->competition_id)->lockForUpdate()->firstOrFail();

        $locked = Entrant::withoutBrandScope()->whereKey($entrant->getKey())->lockForUpdate()->firstOrFail();
        $entrant->setRawAttributes($locked->getAttributes(), true);
    }

    /**
     * @return list<string>
     */
    protected function forfeitOpenMatches(Entrant $entrant): array
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

            // A forfeit settles a disputed match like any other settlement,
            // so the dispute ends with it (fix wave A round 2, M-a). Nobody
            // pressed a button, so no `MatchDisputeResolved` names anyone.
            $this->closeDispute($match, $winnerId, null, __('A team is no longer in the tournament; the match is decided by forfeit.'));

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
            ->whereIn('state', [MatchState::Pending->value, MatchState::Scheduled->value, MatchState::Live->value])
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
     * retired entrant leaves the table either way.
     */
    protected function recomputeCompetition(Entrant $entrant): void
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

    /**
     * Whether any stage of the entrant's competition holds a recorded result
     * — the line past which the draw can no longer be made again.
     */
    protected function competitionHasARecordedResult(Entrant $entrant): bool
    {
        $stages = Stage::withoutBrandScope()
            ->where('brand_id', $entrant->brand_id)
            ->where('competition_id', $entrant->competition_id)
            ->get();

        foreach ($stages as $stage) {
            if ($this->hasRecordedResult($stage)) {
                return true;
            }
        }

        return false;
    }
}

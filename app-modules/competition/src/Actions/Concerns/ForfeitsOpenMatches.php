<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions\Concerns;

use Konventix\Competition\Actions\AdvanceBracket;
use Konventix\Competition\Actions\CalculateStandings;
use Konventix\Competition\Actions\RecordMatchResult;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Enums\StageFormat;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\MatchSlot;
use Konventix\Competition\Models\Stage;

/**
 * Takes a retired entrant out of every open match it still stands in
 * (design §5.5; the three shapes are `DisqualifyEntrant`'s docblock).
 *
 * `DisqualifyEntrant` always runs it. `WithdrawEntrant` and
 * `DeclineIncompleteEntrant` run it once a stage of the competition holds a
 * recorded result: from then on the desk can no longer draw again, so a
 * withdrawn team left standing in its slots would strand the bracket — and,
 * before the start, the start itself (fix wave A round 2, N2).
 */
trait ForfeitsOpenMatches
{
    use RefusesOnceAResultIsRecorded;
    use SettlesAMatch;

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

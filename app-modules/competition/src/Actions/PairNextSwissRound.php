<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Brackets\Formats\SwissGenerator;
use Konventix\Competition\Brackets\Plan\BracketPlan;
use Konventix\Competition\Brackets\PlanWriter;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\Stage;
use Konventix\Competition\Models\Standing;
use Konventix\Competition\Standings\Calculators\SwissStandingsCalculator;
use RuntimeException;

/**
 * Pairs the next round of a Swiss stage from the current standings.
 *
 * This is the piece a Swiss stage cannot exist without and a bracket does not
 * need: an elimination bracket is drawn once and every later match is implied
 * by the graph, whereas a Swiss round only becomes knowable when the previous
 * one finishes. `GenerateBracket` plans round one; each round after it is this.
 *
 * Three refusals, all of them about pairing a round that would be wrong rather
 * than pairing it anyway:
 *
 * - a stage that is not Swiss — the formats that hold a graph must not have
 *   rounds appended to them;
 * - a round that already exists — re-pairing would duplicate the fixtures;
 * - an unfinished previous round — pairing on partial standings produces a
 *   draw that the remaining results immediately invalidate.
 *
 * Standings must be current. Rather than trusting that someone recomputed
 * them, this recomputes first: the pairing is only as good as the order it
 * reads, so reading a stale table is the one failure that would be invisible.
 */
final class PairNextSwissRound
{
    public function __construct(
        private readonly CalculateStandings $standings,
        private readonly PlanWriter $writer,
    ) {}

    public function handle(Stage $stage): BracketPlan
    {
        if ($stage->format !== 'swiss') {
            throw new RuntimeException(sprintf(
                'Stage [%s] is a %s stage; only a Swiss stage pairs rounds one at a time.',
                $stage->id,
                $stage->format,
            ));
        }

        return DB::transaction(function () use ($stage): BracketPlan {
            $played = CompetitionMatch::withoutBrandScope()
                ->where('brand_id', $stage->brand_id)
                ->where('stage_id', $stage->id);

            $lastRound = (int) (clone $played)->max('round');

            if ($lastRound === 0) {
                throw new RuntimeException(sprintf(
                    'Stage [%s] has no round one yet; generate the stage before pairing round two.',
                    $stage->id,
                ));
            }

            $unfinished = (clone $played)
                ->where('round', $lastRound)
                ->where('state', '!=', MatchState::Finished->value)
                ->count();

            if ($unfinished > 0) {
                throw new RuntimeException(sprintf(
                    'Round %d of stage [%s] has %d unfinished match(es); pairing now would draw on partial standings.',
                    $lastRound,
                    $stage->id,
                    $unfinished,
                ));
            }

            $totalRounds = $this->totalRounds($stage);

            if ($lastRound >= $totalRounds) {
                throw new RuntimeException(sprintf(
                    'Stage [%s] has played all %d of its rounds.',
                    $stage->id,
                    $totalRounds,
                ));
            }

            $this->standings->handle($stage);

            $order = Standing::withoutBrandScope()
                ->where('stage_id', $stage->id)
                ->orderBy('placement')
                ->orderBy('entrant_id')
                ->pluck('entrant_id')
                ->all();

            $plan = new BracketPlan(matches: (new SwissGenerator)->pairNextRound(
                array_values(array_map(strval(...), $order)),
                SwissStandingsCalculator::playedMatchups($this->standings->outcomes($stage)),
                $lastRound + 1,
            )->matches);

            $this->writer->write($stage, $plan);

            return $plan;
        });
    }

    /**
     * The stage's round count: whatever it was configured with, or the derived
     * `ceil(log2 n)`.
     */
    private function totalRounds(Stage $stage): int
    {
        $configured = $stage->settings['total_rounds'] ?? null;

        if (is_int($configured) && $configured > 0) {
            return $configured;
        }

        $entrants = CompetitionMatch::withoutBrandScope()
            ->where('brand_id', $stage->brand_id)
            ->where('stage_id', $stage->id)
            ->where('round', 1)
            ->count() * 2;

        return SwissGenerator::roundsFor($entrants);
    }
}

<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\Stage;
use Konventix\Competition\Models\Standing;
use Konventix\Competition\Standings\MatchOutcome;
use Konventix\Competition\Standings\MatchSide;
use Konventix\Competition\Standings\StandingEntry;
use Konventix\Competition\Standings\StandingsCalculatorRegistry;
use Konventix\Competition\Standings\StandingsEntrant;

/**
 * Recomputes a stage's standings from its finished matches and persists them.
 *
 * The only writer, mirroring `GenerateBracket`: the calculators are pure and
 * this is where the database appears. Recomputing the whole stage rather than
 * adjusting rows as results arrive is deliberate — a correction to a match
 * played three rounds ago changes Buchholz for everyone who faced anyone
 * involved, and an incremental update that tried to chase that would be a
 * second, subtly different implementation of the ranking rules.
 *
 * Safe to run repeatedly. It upserts on `(stage_id, entrant_id)` and deletes
 * rows for entrants the recompute no longer ranks, so the table after a run
 * is exactly what the calculator returned — never a merge of this run and the
 * last one.
 */
final class CalculateStandings
{
    public function __construct(
        private readonly StandingsCalculatorRegistry $calculators,
    ) {}

    /**
     * @return list<StandingEntry>
     */
    public function handle(Stage $stage): array
    {
        $calculator = $this->calculators->get($stage->format);

        $entrants = $this->entrants($stage);
        $outcomes = $this->outcomes($stage);

        $entries = $calculator->calculate(
            $entrants,
            $outcomes,
            array_merge($stage->settings ?? [], [
                // The stage's last round, which finished matches alone cannot
                // reveal: a half-played bracket and a finished one look the
                // same to a calculator that only sees results.
                'final_round' => $this->finalRound($stage),
            ]),
        );

        $this->persist($stage, $entries);

        return $entries;
    }

    /**
     * The finished matches of a stage, reduced to what a calculator needs.
     *
     * @return list<MatchOutcome>
     */
    public function outcomes(Stage $stage): array
    {
        $matches = CompetitionMatch::withoutBrandScope()
            ->where('brand_id', $stage->brand_id)
            ->where('stage_id', $stage->id)
            ->where('state', MatchState::Finished->value)
            ->with('slots')
            ->orderBy('round')
            ->orderBy('position')
            ->get();

        $outcomes = [];

        foreach ($matches as $match) {
            $sides = [];

            foreach ($match->slots as $slot) {
                if ($slot->entrant_id === null) {
                    // An unfilled slot is a bye, not a side that scored zero.
                    continue;
                }

                $sides[] = new MatchSide($slot->entrant_id, $slot->score);
            }

            $outcomes[] = new MatchOutcome(
                round: $match->round,
                sides: $sides,
                bracket: $match->bracket,
                winnerEntrantId: $match->winner_entrant_id,
            );
        }

        return $outcomes;
    }

    private function finalRound(Stage $stage): int
    {
        return (int) CompetitionMatch::withoutBrandScope()
            ->where('brand_id', $stage->brand_id)
            ->where('stage_id', $stage->id)
            ->max('round');
    }

    /**
     * @return list<StandingsEntrant>
     */
    private function entrants(Stage $stage): array
    {
        return array_values(Entrant::withoutBrandScope()
            ->where('brand_id', $stage->brand_id)
            ->where('competition_id', $stage->competition_id)
            ->whereIn('state', [EntrantState::Registered->value, EntrantState::CheckedIn->value])
            ->orderByRaw('seed IS NULL')
            ->orderBy('seed')
            ->orderBy('created_at')
            ->get()
            ->map(fn (Entrant $entrant): StandingsEntrant => new StandingsEntrant($entrant->id, $entrant->seed))
            ->all());
    }

    /**
     * @param  list<StandingEntry>  $entries
     */
    private function persist(Stage $stage, array $entries): void
    {
        DB::transaction(function () use ($stage, $entries): void {
            $now = now();
            $keep = [];

            foreach ($entries as $entry) {
                $standing = Standing::withoutBrandScope()->firstOrNew([
                    'stage_id' => $stage->id,
                    'entrant_id' => $entry->entrantId,
                ]);

                $standing->fill([
                    'competition_id' => $stage->competition_id,
                    'group' => $entry->group,
                    'placement' => $entry->placement,
                    'wins' => $entry->wins,
                    'losses' => $entry->losses,
                    'draws' => $entry->draws,
                    'points' => $entry->points,
                    'tiebreaker' => $entry->tiebreaker,
                    'computed_at' => $now,
                ]);

                // brand_id from the stage, not ambient context (ADR-0010).
                $standing->forceFill(['brand_id' => $stage->brand_id])->save();

                $keep[] = $standing->id;
            }

            // Anything the recompute did not produce no longer belongs in the
            // table — a withdrawn entrant must disappear from the standings,
            // not linger at their last known placement.
            Standing::withoutBrandScope()
                ->where('stage_id', $stage->id)
                ->whereNotIn('id', $keep === [] ? [''] : $keep)
                ->delete();
        });
    }
}

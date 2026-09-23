<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\ScoreEntry;
use Konventix\Competition\Models\Stage;
use Konventix\Competition\Models\Standing;
use Konventix\Competition\Seeding\SignupField;
use Konventix\Competition\Standings\LeaderboardStandingsCalculator;
use Konventix\Competition\Standings\MatchOutcome;
use Konventix\Competition\Standings\MatchSide;
use Konventix\Competition\Standings\ScoredEntry;
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
 *
 * **A leaderboard stage ranks here too** (design §7.2: "`CalculateStandings`
 * recomputes the stage wholesale as it already does"), and it is the one
 * branch in this class. The two paths meet only at `persist()`: a leaderboard
 * has no matches, so `LeaderboardStandingsCalculator` takes score entries
 * where the bracket calculators take outcomes and is deliberately a separate
 * class with a separate signature rather than one interface bent over both.
 * What they share is the table they land in, which has to be one table —
 * every page that reads standings reads `competition_standings`, whatever
 * produced them.
 */
final class CalculateStandings
{
    public function __construct(
        private readonly StandingsCalculatorRegistry $calculators,
        private readonly LeaderboardStandingsCalculator $leaderboard,
        private readonly SignupField $field,
    ) {}

    /**
     * @return list<StandingEntry>
     */
    public function handle(Stage $stage): array
    {
        $entrants = $this->entrants($stage);

        $entries = $stage->isLeaderboard()
            ? $this->leaderboard->calculate($entrants, $this->scoredEntries($stage), $stage->leaderboardSettings())
            : $this->calculators->get($stage->format)->calculate(
                $entrants,
                $this->outcomes($stage),
                array_merge($stage->settings ?? [], [
                    // The stage's last round, which finished matches alone
                    // cannot reveal: a half-played bracket and a finished one
                    // look the same to a calculator that only sees results.
                    'final_round' => $this->finalRound($stage),
                ]),
            );

        $this->persist($stage, $entries);

        return $entries;
    }

    /**
     * A leaderboard stage's entries, reduced to what its calculator needs
     * (design §7.2).
     *
     * Unverified rows travel with the rest, carrying their state: "only
     * verified entries count" is a ranking rule and belongs where the ranking
     * is proved, not in this query's `where` clause.
     *
     * @return list<ScoredEntry>
     */
    public function scoredEntries(Stage $stage): array
    {
        return array_values(ScoreEntry::withoutBrandScope()
            ->where('brand_id', $stage->brand_id)
            ->where('stage_id', $stage->id)
            ->orderBy('heat')
            ->orderBy('created_at')
            ->get()
            ->map(fn (ScoreEntry $entry): ScoredEntry => new ScoredEntry(
                entrantId: $entry->entrant_id,
                value: $entry->value,
                heat: $entry->heat,
                verified: $entry->isVerified(),
            ))
            ->all());
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
     * Who is ranked.
     *
     * A leaderboard has no draw, so its field is `SignupField`'s: where the
     * competition checks in, the checked-in only. Ranking every registered
     * entrant put the no-shows of a check-in competition on the public board
     * at a shared last place (final review, part 1, I6). A bracket keeps the
     * wider field — its calculators rank from the matches, and an entrant who
     * played is in them whatever its check-in says.
     *
     * @return list<StandingsEntrant>
     */
    private function entrants(Stage $stage): array
    {
        $states = $stage->isLeaderboard()
            ? $this->field->seedableStates($stage)
            : [EntrantState::Registered->value, EntrantState::CheckedIn->value];

        return array_values(Entrant::withoutBrandScope()
            ->where('brand_id', $stage->brand_id)
            ->where('competition_id', $stage->competition_id)
            ->whereIn('state', $states)
            ->orderByRaw('seed IS NULL')
            ->orderBy('seed')
            ->orderBy('created_at')
            ->orderBy('id')
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

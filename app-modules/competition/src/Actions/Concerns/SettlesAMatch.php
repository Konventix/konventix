<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions\Concerns;

use Konventix\Competition\Actions\AdvanceBracket;
use Konventix\Competition\Actions\RecordMatchResult;
use Konventix\Competition\Brackets\FormatRegistry;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Enums\StageFormat;
use Konventix\Competition\Exceptions\ResultSubmissionException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\Discipline;
use Konventix\Competition\Models\MatchConnection;
use Konventix\Competition\Models\MatchSlot;
use Konventix\Competition\Models\Stage;

/**
 * The three things that always happen together once a head-to-head result is
 * agreed (design §8.2): the result is recorded, the bracket advances, and the
 * standings are recomputed.
 *
 * Shared by `ReportMatchResult` (when the second side's report agrees with
 * the first), `ConfirmMatchResult` and `ResolveDispute`, because all three
 * end in the same place by different routes and a copy each would be three
 * chances to forget the recompute. `DisqualifyEntrant` already does the first
 * two by hand for a forfeit and is left alone: it settles a whole field of
 * matches in one pass.
 *
 * It also holds the **position ↔ entrant** translation. A report is keyed by
 * slot position (§8.2) because a correction can re-route who stands in a slot
 * (§8.5); `RecordMatchResult` is keyed by entrant because that is what
 * `MatchFinished` carries to GameOps. One place does the conversion.
 */
trait SettlesAMatch
{
    use RecomputesStageStandings;

    /**
     * Record an agreed result, advance the bracket, recompute the standings.
     *
     * @param  array<int, int|null>  $scoresByPosition
     */
    protected function settleMatch(
        CompetitionMatch $match,
        array $scoresByPosition,
        ?string $winnerEntrantId,
        ?string $recordedByUserId = null,
    ): CompetitionMatch {
        $settled = app(RecordMatchResult::class)->handle(
            $match,
            $this->scoresByEntrant($match, $scoresByPosition),
            $winnerEntrantId,
            $recordedByUserId,
        );

        app(AdvanceBracket::class)->handle($settled);

        $this->recomputeStandings($settled);

        return $settled->refresh();
    }

    /**
     * The match's sides, `position => entrant ULID`, unfilled slots left out.
     *
     * @return array<int, string>
     */
    protected function entrantIdsByPosition(CompetitionMatch $match): array
    {
        $byPosition = [];

        $slots = MatchSlot::withoutBrandScope()
            ->where('brand_id', $match->brand_id)
            ->where('competition_match_id', $match->id)
            ->orderBy('position')
            ->get();

        foreach ($slots as $slot) {
            if ($slot->entrant_id !== null) {
                $byPosition[$slot->position] = $slot->entrant_id;
            }
        }

        return $byPosition;
    }

    /**
     * A position-keyed claim, translated into what `RecordMatchResult` takes.
     * A score for a position nobody occupies is dropped rather than written
     * against a null entrant.
     *
     * @param  array<int, int|null>  $scoresByPosition
     * @return array<string, int|null>
     */
    protected function scoresByEntrant(CompetitionMatch $match, array $scoresByPosition): array
    {
        $entrants = $this->entrantIdsByPosition($match);
        $scores = [];

        foreach ($scoresByPosition as $position => $score) {
            if (isset($entrants[$position])) {
                $scores[$entrants[$position]] = $score;
            }
        }

        return $scores;
    }

    /**
     * Who won, given a position-keyed claim: the single highest score.
     *
     * A tie is **null**, not a coin toss — a drawn match is a real outcome for
     * a discipline that allows draws (`disciplines.allow_draws`), and for one
     * that does not it is a result the desk has to look at rather than one
     * this code should invent a winner for. A missing score counts as no
     * score, which is what a bye already looks like.
     *
     * @param  array<int, int|null>  $scoresByPosition
     */
    protected function winnerFromScores(CompetitionMatch $match, array $scoresByPosition): ?string
    {
        $entrants = $this->entrantIdsByPosition($match);
        $best = null;
        $leaders = [];

        foreach ($scoresByPosition as $position => $score) {
            if ($score === null || ! isset($entrants[$position])) {
                continue;
            }

            if ($best === null || $score > $best) {
                $best = $score;
                $leaders = [$entrants[$position]];

                continue;
            }

            if ($score === $best) {
                $leaders[] = $entrants[$position];
            }
        }

        return count($leaders) === 1 ? $leaders[0] : null;
    }

    /**
     * Refuses to settle a match one of whose sides is **still to come**: an
     * empty slot fed by an edge whose source match is not finished (§7.4 —
     * the progression graph says who fills it). An empty slot with no such
     * edge is a **bye**, which is settled by giving the lone side the match,
     * and stays allowed; so does a slot whose feeding match is over and left
     * it empty (a double-elimination loser slot fed by a bye).
     *
     * @param  array<int, string>  $sides  position => entrant ULID, filled slots only
     */
    protected function guardEverySideHasArrived(CompetitionMatch $match, array $sides): void
    {
        $awaited = $sides === [] || MatchConnection::withoutBrandScope()
            ->where('brand_id', $match->brand_id)
            ->where('target_match_id', $match->id)
            ->whereNotIn('target_slot', array_keys($sides))
            ->whereIn('source_match_id', CompetitionMatch::withoutBrandScope()
                ->select('id')
                ->where('brand_id', $match->brand_id)
                ->where('state', '!=', MatchState::Finished->value))
            ->exists();

        if ($awaited) {
            throw ResultSubmissionException::sideStillToCome($match->id);
        }
    }

    /**
     * Refuses a draw (a null winner) where none is possible: always on an
     * elimination bracket, which cannot send "nobody" on to the next round
     * and would dead-end; elsewhere unless both the discipline (ADR-0062,
     * the source of record for draws) and the stage's format settings allow
     * one. A bye is never a draw — its lone side is the winner.
     */
    protected function guardDrawIsPossible(CompetitionMatch $match, ?string $winnerEntrantId): void
    {
        if ($winnerEntrantId !== null) {
            return;
        }

        /** @var Stage $stage */
        $stage = Stage::withoutBrandScope()->whereKey($match->stage_id)->firstOrFail();

        /** @var Competition $competition */
        $competition = Competition::withoutBrandScope()->whereKey($match->competition_id)->firstOrFail();

        /** @var Discipline $discipline */
        $discipline = Discipline::withoutBrandScope()->whereKey($competition->discipline_id)->firstOrFail();

        $elimination = in_array($stage->stageFormat(), [StageFormat::SingleElimination, StageFormat::DoubleElimination], true);

        $settings = app(FormatRegistry::class)->has($stage->format)
            ? array_merge(app(FormatRegistry::class)->get($stage->format)->defaults(), $stage->settings ?? [])
            : [];

        if ($elimination || ! $discipline->allow_draws || ! (bool) ($settings['allow_draws'] ?? false)) {
            throw ResultSubmissionException::drawNotPossible($match->id, $stage->format);
        }
    }
}
